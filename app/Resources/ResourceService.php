<?php

namespace App\Resources;

use App\Models\Record;
use App\Services\TitleService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use App\Support\Outbox;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Authorized, validated CRUD over registry resources. All generic writes pass through here. */
final class ResourceService
{
    public const DECIMAL_SCALES = ['m' => 4, 'q' => 10, 'p' => 8, 'r' => 8, 'f' => 12, 'pct' => 4];

    public function __construct(private readonly AccessContext $ctx) {}

    public function definition(string $type): array
    {
        $def = Registry::get($type);
        if (! $def) {
            throw ApiError::notFound("Unknown resource type '{$type}'.");
        }

        return $def;
    }

    public function model(array $def): Record
    {
        return Record::for($def['table'] ?? $def['type'], $this->casts($def));
    }

    public function casts(array $def): array
    {
        $casts = $def['casts'] ?? [];
        foreach ($def['fields'] as $name => $spec) {
            $f = Registry::field($spec);
            $casts[$name] ??= match ($f['type']) {
                'j' => 'array',
                'b' => 'boolean',
                'dt' => 'datetime',
                'i', 'y' => 'integer',
                default => null,
            };
        }
        foreach ($def['heavy'] ?? [] as $h) {
            $casts[$h] = 'array';
        }

        return array_filter($casts);
    }

    /** Visibility-constrained base query. */
    public function query(array $def, string $action = 'read'): Builder
    {
        $table = $def['table'] ?? $def['type'];
        $q = $this->model($def)->newQuery();
        if ($def['unscoped'] ?? false) {
            $q->where($table.'.workspace_id', $this->ctx->workspace->id);
            if (! $this->ctx->canAny($def['domain'], $action)) {
                $q->whereRaw('1 = 0');
            }

            return $q;
        }
        if ($def['domain'] === 'dynamic') {
            $q->where($table.'.workspace_id', $this->ctx->workspace->id);
            $q->where(function ($w) use ($action, $table) {
                $w->whereRaw('1 = 0');
                foreach (\App\Support\Domains::ALL as $d) {
                    $ids = $this->ctx->scopeIds($d, $action);
                    if ($ids) {
                        $w->orWhere(fn ($c) => $c->where($table.'.domain', $d)->whereIn($table.'.scope_id', $ids));
                    }
                }
            });

            return $q;
        }

        return $this->ctx->constrain($q, $def['domain'], $action, $def['year'] ?? null, $table);
    }

    public function list(string $type, array $params): CursorPaginator
    {
        $def = $this->definition($type);
        $table = $def['table'] ?? $def['type'];
        $q = $this->query($def);
        if (empty($params['archived'])) {
            $q->whereNull($table.'.archived_at');
        }
        $filterable = array_unique(array_merge($def['filters'] ?? [], array_filter([$def['parent'] ?? null]), $this->enumAndRefFields($def), ['scope_id', 'status']));
        foreach ((array) ($params['filter'] ?? []) as $field => $value) {
            if (in_array($field, $filterable, true) && (isset($def['fields'][$field]) || $field === 'scope_id') && is_scalar($value)) {
                $q->where($table.'.'.$field, $value);
            }
        }
        if (! empty($params['q']) && ! empty($def['search'])) {
            $term = '%'.addcslashes(mb_substr((string) $params['q'], 0, 100), '%_\\').'%';
            $q->where(function ($w) use ($def, $term, $table) {
                foreach ($def['search'] as $col) {
                    $w->orWhere($table.'.'.$col, 'like', $term);
                }
            });
        }
        if (($dateCol = $def['date'] ?? null) && (! empty($params['from']) || ! empty($params['to']))) {
            $from = $params['from'] ?? null;
            $to = $params['to'] ?? null;
            foreach ([$from, $to] as $d) {
                if ($d !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) {
                    throw new ApiError('invalid_filter', 'Dates must be YYYY-MM-DD.');
                }
            }
            if ($from && $to && (strtotime($to) - strtotime($from)) > 86400 * 366 * 10) {
                throw new ApiError('invalid_filter', 'Date interval is limited to 10 years.');
            }
            $from && $q->where($table.'.'.$dateCol, '>=', $from);
            $to && $q->where($table.'.'.$dateCol, '<=', $to);
        }
        [$col, $dir] = $def['order'] ?? ['created_at', 'desc'];
        $q->orderBy($table.'.'.$col, $dir)->orderBy($table.'.id', $dir);
        $limit = max(1, min(200, (int) ($params['limit'] ?? 50)));
        $columns = $this->listColumns($def, $table);

        return $q->select($columns)->cursorPaginate($limit, ['*'], 'cursor', $params['cursor'] ?? null);
    }

    public function find(string $type, string $id, string $action = 'read'): Record
    {
        $def = $this->definition($type);
        $table = $def['table'] ?? $def['type'];
        if (! Str::isUlid($id)) {
            throw ApiError::notFound();
        }
        $row = $this->query($def, $action)->where($table.'.id', $id)->first();
        if (! $row) {
            // Same response whether the row is missing or invisible: no existence oracle.
            throw ApiError::notFound();
        }

        return $row;
    }

    public function create(string $type, array $input): Record
    {
        $def = $this->definition($type);
        if (($def['readonly'] ?? false) || ($def['no_create'] ?? false)) {
            throw new ApiError('read_only', 'This resource is managed by the application.', 405);
        }
        $scopeId = $this->resolveWriteScope($def, $input['scope_id'] ?? null, $input['domain'] ?? null);
        $data = $this->validate($def, $input, true);
        $this->assertRefs($def, $data);

        return DB::transaction(function () use ($def, $data, $scopeId, $type) {
            $m = $this->model($def);
            $m->forceFill($data + [
                'workspace_id' => $this->ctx->workspace->id,
                'created_by' => $this->ctx->user->id,
                'updated_by' => $this->ctx->user->id,
            ]);
            if (! ($def['unscoped'] ?? false)) {
                $m->scope_id = $scopeId;
            }
            $m->save();
            Audit::log("{$type}.created", $type, $m->id, ['fields' => array_keys($data)], scopeId: $scopeId);
            Outbox::publish('record.created', ['type' => $type, 'id' => $m->id], $type, $m->id);
            $type === 'ownership_interests' && TitleService::syncDerived($this->ctx->workspace->id, (string) $m->subject_type, (string) $m->subject_id);

            return $m->fresh() ?? $m;
        });
    }

    public function update(string $type, string $id, array $input, ?int $ifMatch): Record
    {
        $def = $this->definition($type);
        if ($def['readonly'] ?? false) {
            throw new ApiError('read_only', 'This resource is managed by the application.', 405);
        }
        $row = $this->find($type, $id, 'write');
        $this->assertVersion($row, $ifMatch);
        $data = $this->validate($def, $input, false);
        $this->assertRefs($def, $data);
        $newScope = $input['scope_id'] ?? null;
        if ($newScope && $newScope !== $row->scope_id) {
            $this->resolveWriteScope($def, $newScope, $row->domain ?? null);
            $data['scope_id'] = $newScope;
        }

        return DB::transaction(function () use ($row, $data, $type, $def) {
            $table = $def['table'] ?? $def['type'];
            $affected = DB::table($table)->where('id', $row->id)->where('edit_version', $row->edit_version)
                ->update($this->toColumns($def, $data) + ['edit_version' => $row->edit_version + 1, 'updated_by' => $this->ctx->user->id, 'updated_at' => now()]);
            if ($affected !== 1) {
                throw ApiError::conflict('This record was changed by someone else. Reload and try again.');
            }
            Audit::log("{$type}.updated", $type, $row->id, ['fields' => array_keys($data)], scopeId: $data['scope_id'] ?? $row->scope_id);
            Outbox::publish('record.updated', ['type' => $type, 'id' => $row->id, 'fields' => array_keys($data)], $type, $row->id);
            if ($type === 'ownership_interests') {
                $fresh = DB::table('ownership_interests')->find($row->id);
                TitleService::syncDerived($this->ctx->workspace->id, (string) $row->subject_type, (string) $row->subject_id);
                ($fresh->subject_id ?? null) !== $row->subject_id && TitleService::syncDerived($this->ctx->workspace->id, (string) $fresh->subject_type, (string) $fresh->subject_id);
            }

            return $this->find($type, $row->id);
        });
    }

    public function archive(string $type, string $id, ?int $ifMatch): void
    {
        $def = $this->definition($type);
        if ($def['readonly'] ?? false) {
            throw new ApiError('read_only', 'This resource is managed by the application.', 405);
        }
        $row = $this->find($type, $id, 'write');
        $this->assertVersion($row, $ifMatch);
        DB::transaction(function () use ($def, $row, $type) {
            DB::table($def['table'] ?? $def['type'])->where('id', $row->id)
                ->update(['archived_at' => now(), 'edit_version' => $row->edit_version + 1, 'updated_by' => $this->ctx->user->id]);
            Audit::log("{$type}.archived", $type, $row->id, scopeId: $row->scope_id);
            Outbox::publish('record.archived', ['type' => $type, 'id' => $row->id], $type, $row->id);
            $type === 'ownership_interests' && TitleService::syncDerived($this->ctx->workspace->id, (string) $row->subject_type, (string) $row->subject_id);
        });
    }

    /** Serialize with decimal strings and field-level redaction. */
    public function present(array $def, Record $row, bool $full = true): array
    {
        $out = ['id' => $row->id, 'type' => $def['type']];
        if (! ($def['unscoped'] ?? false)) {
            $out['scope_id'] = $row->scope_id;
        }
        $redacted = [];
        foreach ($def['fields'] as $name => $spec) {
            if (! $full && ! in_array($name, $def['list'] ?? [], true)) {
                continue;
            }
            if (! array_key_exists($name, $row->getAttributes())) {
                continue;
            }
            $need = $def['sensitive'][$name] ?? null;
            if ($need && ! $this->ctx->can($need, 'read', $row->scope_id ?? null)) {
                $out[$name] = null;
                $redacted[] = $name;
                continue;
            }
            $out[$name] = $this->presentValue(Registry::field($spec)['type'], $row->getAttribute($name));
        }
        if ($full) {
            foreach ($def['heavy'] ?? [] as $h) {
                if (array_key_exists($h, $row->getAttributes())) {
                    $out[$h] = $row->getAttribute($h);
                }
            }
        }
        $title = $def['title'] ?? null;
        $out['_title'] = $title && isset($out[$title]) ? (string) $out[$title] : null;
        $out['edit_version'] = (int) ($row->edit_version ?? 1);
        $out['created_at'] = optional($row->created_at)->toIso8601String();
        $out['updated_at'] = optional($row->updated_at)->toIso8601String();
        if ($row->getAttribute('archived_at')) {
            $out['archived_at'] = (string) $row->getAttribute('archived_at');
        }
        if ($redacted) {
            $out['_redacted'] = $redacted;
        }

        return $out;
    }

    private function presentValue(string $type, mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if (isset(self::DECIMAL_SCALES[$type])) {
            return Dec::tryNorm($v, self::DECIMAL_SCALES[$type]);
        }

        return match ($type) {
            'd' => substr((string) $v, 0, 10),
            'dt' => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : (string) $v,
            'i', 'y' => (int) $v,
            'b' => (bool) $v,
            default => $v,
        };
    }

    private function listColumns(array $def, string $table): array
    {
        $cols = ['id', 'workspace_id', 'edit_version', 'created_at', 'updated_at', 'archived_at'];
        if (! ($def['unscoped'] ?? false)) {
            $cols[] = 'scope_id';
        }
        foreach ($def['fields'] as $name => $spec) {
            $cols[] = $name;
        }

        return array_map(fn ($c) => $table.'.'.$c, array_unique($cols));
    }

    private function enumAndRefFields(array $def): array
    {
        $out = [];
        foreach ($def['fields'] as $name => $spec) {
            $f = Registry::field($spec);
            if (in_array($f['type'], ['enum', 'ref', 'b', 'y', 'poly'], true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    private function resolveWriteScope(array $def, ?string $scopeId, ?string $dynamicDomain): ?string
    {
        if ($def['unscoped'] ?? false) {
            if (! $this->ctx->canAny($def['domain'], 'write')) {
                throw ApiError::forbidden();
            }

            return null;
        }
        $domain = $def['domain'] === 'dynamic' ? ($dynamicDomain ?: 'governance') : $def['domain'];
        if (! \App\Support\Domains::valid($domain)) {
            throw new ApiError('validation_failed', 'Invalid domain.', 422);
        }
        $writable = $this->ctx->scopeIds($domain, 'write');
        if ($scopeId === null) {
            if (count($writable) === 1) {
                return $writable[0];
            }
            throw new ApiError('validation_failed', 'Choose a privacy scope for this record.', 422, ['scope_id' => ['required']]);
        }
        if (! in_array($scopeId, $writable, true)) {
            throw ApiError::forbidden('You cannot write to that scope.');
        }

        return $scopeId;
    }

    private function assertVersion(Record $row, ?int $ifMatch): void
    {
        if ($ifMatch === null) {
            throw new ApiError('precondition_required', 'If-Match header with the current edit_version is required.', 428);
        }
        if ((int) $row->edit_version !== $ifMatch) {
            throw ApiError::conflict('This record was changed by someone else. Reload and try again.', ['current_version' => (int) $row->edit_version]);
        }
    }

    /** @return array<string,mixed> */
    public function validate(array $def, array $input, bool $creating): array
    {
        $rules = [];
        $data = [];
        foreach ($def['fields'] as $name => $spec) {
            $f = Registry::field($spec);
            if ($f['readonly'] || ! array_key_exists($name, $input)) {
                if ($creating && $f['required'] && ! $f['readonly']) {
                    $rules[$name] = ['required'];
                }
                continue;
            }
            $r = [$f['required'] ? 'required' : 'nullable'];
            $r = array_merge($r, match ($f['type']) {
                's' => ['string', 'max:255'],
                't' => ['string', 'max:20000'],
                'i' => ['integer', 'between:-2147483648,2147483647'],
                'y' => ['integer', 'between:1900,2200'],
                'd' => ['date_format:Y-m-d'],
                'dt' => ['date'],
                'b' => ['boolean'],
                'j' => ['array'],
                'cur' => ['string', 'regex:/^[A-Z]{3}$/'],
                'enum' => ['string', 'in:'.implode(',', $f['values'])],
                'ref', 'poly' => ['string', 'size:26'],
                default => isset(self::DECIMAL_SCALES[$f['type']]) ? ['regex:/^-?\d{1,16}(\.\d{1,12})?$/'] : ['string'],
            });
            if (isset($def['patterns'][$name])) {
                $r[] = 'regex:'.$def['patterns'][$name];
            }
            $rules[$name] = $r;
            $data[$name] = $input[$name];
        }
        $v = Validator::make($input, $rules);
        if ($v->fails()) {
            throw new ApiError('validation_failed', 'Some fields need attention.', 422, $v->errors()->toArray());
        }
        foreach ($data as $name => $value) {
            $type = Registry::field($def['fields'][$name])['type'];
            if ($value !== null && isset(self::DECIMAL_SCALES[$type])) {
                $data[$name] = Dec::norm((string) $value, self::DECIMAL_SCALES[$type]);
            }
            if ($type === 'f' && $value !== null && (Dec::cmp($data[$name], '0') < 0 || Dec::cmp($data[$name], '1') > 0)) {
                throw new ApiError('validation_failed', 'Fractions must be between 0 and 1.', 422, [$name => ['between 0 and 1']]);
            }
        }

        return $data;
    }

    /** Every reference must point at a record in this workspace that the writer can read. */
    private function assertRefs(array $def, array $data): void
    {
        foreach ($data as $name => $value) {
            if ($value === null) {
                continue;
            }
            $f = Registry::field($def['fields'][$name]);
            $target = null;
            if ($f['type'] === 'ref') {
                $target = $f['ref'];
            } elseif ($f['type'] === 'poly') {
                $typeField = $def['poly'][$name] ?? null;
                $subjectType = $data[$typeField] ?? null;
                if ($typeField && $subjectType === null) {
                    throw new ApiError('validation_failed', "Set {$typeField} together with {$name}.", 422);
                }
                if (in_array($subjectType, ['class', 'estate', 'will', 'none', 'vehicle'], true)) {
                    continue;
                }
                $target = Registry::SUBJECT_MAP[$subjectType] ?? null;
                if ($subjectType === 'charity') {
                    $target = 'contacts';
                }
            }
            if ($target === null) {
                continue;
            }
            $tdef = Registry::get($target);
            if ($tdef === null || ! $this->query($tdef)->where(($tdef['table'] ?? $target).'.id', $value)->exists()) {
                throw new ApiError('validation_failed', "Referenced record for {$name} was not found.", 422, [$name => ['not found']]);
            }
        }
    }

    private function toColumns(array $def, array $data): array
    {
        $out = [];
        $casts = $this->casts($def);
        foreach ($data as $k => $v) {
            if (($casts[$k] ?? null) === 'array') {
                $out[$k] = $v === null ? null : json_encode($v);
            } elseif (($casts[$k] ?? null) === 'encrypted') {
                $out[$k] = $v === null ? null : encrypt($v, false);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
