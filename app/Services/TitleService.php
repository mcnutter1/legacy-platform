<?php

namespace App\Services;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Title holders of a property, asset, account or debt: sole or joint (JTWROS, tenancy by the entirety, tenants in common…). */
final class TitleService
{
    public const SUBJECTS = ['property' => ['properties', 'real_estate'], 'asset' => ['assets', 'assets'], 'account' => ['accounts', 'ledger'], 'liability' => ['liabilities', 'debt']];

    public const FORMS = [
        'sole' => 'Sole owner',
        'jtwros' => 'Joint tenants with right of survivorship',
        'tenancy_by_entirety' => 'Tenancy by the entirety (spouses)',
        'tenants_in_common' => 'Tenants in common',
        'community' => 'Community property (spouses)',
        'trust' => 'Held in trust',
        'entity' => 'Held by an entity',
    ];

    /** Subject columns that mirror the Ownership tab for older readers: a single person or entity owner, else empty. */
    public const DERIVED = ['account' => ['person' => 'owner_person_id', 'entity' => 'owner_entity_id'], 'property' => ['entity' => 'entity_id'], 'asset' => ['entity' => 'entity_id']];

    public static function syncDerived(string $workspaceId, string $type, string $subjectId): void
    {
        $cols = self::DERIVED[$type] ?? null;
        if (! $cols) {
            return;
        }
        $today = now()->toDateString();
        $rows = DB::table('ownership_interests')->where('workspace_id', $workspaceId)->where('subject_type', $type)->where('subject_id', $subjectId)
            ->where('basis', 'legal_title')->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->get(['owner_type', 'owner_id']);
        $set = array_fill_keys(array_values($cols), null);
        if ($rows->count() === 1 && isset($cols[$rows[0]->owner_type])) {
            $set[$cols[$rows[0]->owner_type]] = $rows[0]->owner_id;
        }
        DB::table(self::SUBJECTS[$type][0])->where('workspace_id', $workspaceId)->where('id', $subjectId)->update($set);
    }

    public function __construct(private readonly AccessContext $ctx) {}

    public function current(string $type, string $id): array
    {
        $subject = $this->subject($type, $id, 'read');
        $today = now()->toDateString();
        $rows = DB::table('ownership_interests')->where('workspace_id', $this->ctx->workspace->id)->where('subject_type', $type)->where('subject_id', $subject->id)
            ->where('basis', 'legal_title')->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->orderByDesc('fraction')->get();
        $names = $this->names($rows->map(fn ($r) => [$r->owner_type, $r->owner_id])->all());
        $total = $rows->reduce(fn ($s, $r) => bcadd($s, Dec::norm($r->fraction, 12), 12), '0');

        return [
            'subject_type' => $type, 'subject_id' => $subject->id, 'title_form' => $rows->first()->title_form ?? null,
            'owners' => $rows->map(fn ($r) => ['owner_type' => $r->owner_type, 'owner_id' => $r->owner_id, 'name' => $names[$r->owner_type.':'.$r->owner_id] ?? 'Restricted',
                'fraction' => Dec::norm($r->fraction, 12), 'title_form' => $r->title_form, 'effective_from' => $r->effective_from])->values()->all(),
            'complete' => Dec::cmp(Dec::round($total, 6), '1.000000') === 0,
            'forms' => self::FORMS,
        ];
    }

    /** Replace the current title holders as of a date; earlier interests are end-dated, not deleted. */
    public function set(string $type, string $id, array $data): array
    {
        $subject = $this->subject($type, $id, 'write');
        if (! $this->ctx->can('household', 'write', $subject->scope_id)) {
            throw ApiError::forbidden('You need household write access in this scope to change ownership.');
        }
        $form = (string) ($data['title_form'] ?? '');
        if (! isset(self::FORMS[$form])) {
            throw new ApiError('validation_failed', 'Choose how title is held.', 422);
        }
        $from = (string) ($data['effective_from'] ?? now()->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! strtotime($from)) {
            throw new ApiError('validation_failed', 'Effective date must be YYYY-MM-DD.', 422);
        }
        $owners = array_values(array_filter((array) ($data['owners'] ?? []), 'is_array'));
        $keys = array_map(fn ($o) => ($o['owner_type'] ?? '').':'.($o['owner_id'] ?? ''), $owners);
        if (! $owners || count($owners) > 12 || count(array_unique($keys)) !== count($keys)) {
            throw new ApiError('validation_failed', 'List each owner once (1–12 owners).', 422);
        }
        foreach ($owners as $o) {
            $table = match ($o['owner_type'] ?? null) { 'person' => 'persons', 'entity' => 'legal_entities', default => null };
            if (! $table || ! Str::isUlid((string) ($o['owner_id'] ?? '')) || ! DB::table($table)->where('workspace_id', $this->ctx->workspace->id)->where('id', $o['owner_id'])->whereNull('archived_at')->exists()) {
                throw new ApiError('validation_failed', 'An owner was not found in this workspace.', 422);
            }
        }
        $n = count($owners);
        $persons = array_filter($owners, fn ($o) => $o['owner_type'] === 'person');
        $equal = in_array($form, ['jtwros', 'tenancy_by_entirety', 'community'], true) || $n === 1;
        match ($form) {
            'sole' => $n === 1 ?: throw new ApiError('validation_failed', 'Sole ownership has exactly one owner.', 422),
            'jtwros', 'tenants_in_common' => $n >= 2 ?: throw new ApiError('validation_failed', 'Joint ownership needs at least two owners.', 422),
            'tenancy_by_entirety', 'community' => (count($persons) === 2 && $n === 2) ?: throw new ApiError('validation_failed', 'This form of title is held by exactly two spouses.', 422),
            'trust', 'entity' => count($persons) === 0 ?: throw new ApiError('validation_failed', 'Trust or entity title is held by legal entities, not individuals.', 422),
        };
        if ($form === 'tenancy_by_entirety' && $type !== 'property') {
            throw new ApiError('validation_failed', 'Tenancy by the entirety applies to real property only.', 422);
        }
        if (in_array($form, ['tenancy_by_entirety', 'community'], true) && ! $this->spouses(array_column($persons, 'owner_id'))) {
            throw new ApiError('validation_failed', 'Record these two people as spouses or domestic/civil-union partners (People & entities → Relationships) first.', 422);
        }
        // Survivorship forms are equal by law; compute shares so they always total exactly 100%.
        $fractions = $equal ? $this->equalShares($n) : array_map(fn ($o) => Dec::tryNorm($o['fraction'] ?? null, 12), $owners);
        foreach ($fractions as $f) {
            if ($f === null || Dec::cmp($f, '0') <= 0 || Dec::cmp($f, '1') > 0) {
                throw new ApiError('validation_failed', 'Each owner needs a share between 0 and 100%.', 422);
            }
        }
        if (Dec::cmp(Dec::round(array_reduce($fractions, fn ($s, $f) => bcadd($s, $f, 12), '0'), 6), '1.000000') !== 0) {
            throw new ApiError('validation_failed', 'Shares must add up to 100%.', 422);
        }
        $evidence = $data['evidence_document_id'] ?? null;
        if ($evidence && ! DB::table('documents')->where('workspace_id', $this->ctx->workspace->id)->where('id', $evidence)->exists()) {
            throw new ApiError('validation_failed', 'Evidence document not found.', 422);
        }

        DB::transaction(function () use ($type, $subject, $owners, $fractions, $form, $from, $evidence) {
            $open = DB::table('ownership_interests')->where('workspace_id', $this->ctx->workspace->id)->where('subject_type', $type)->where('subject_id', $subject->id)
                ->where('basis', 'legal_title')->whereNull('archived_at')->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->lockForUpdate()->get();
            foreach ($open as $r) {
                // Interests that started before the new date keep their history; ones starting on/after it are superseded.
                $r->effective_from !== null && $r->effective_from < $from
                    ? DB::table('ownership_interests')->where('id', $r->id)->update(['effective_to' => date('Y-m-d', strtotime($from.' -1 day')), 'edit_version' => $r->edit_version + 1, 'updated_at' => now(), 'updated_by' => $this->ctx->user->id])
                    : DB::table('ownership_interests')->where('id', $r->id)->update(['archived_at' => now(), 'edit_version' => $r->edit_version + 1, 'updated_at' => now(), 'updated_by' => $this->ctx->user->id]);
            }
            foreach ($owners as $i => $o) {
                DB::table('ownership_interests')->insert([
                    'id' => (string) Str::ulid(), 'workspace_id' => $this->ctx->workspace->id, 'scope_id' => $subject->scope_id, 'owner_type' => $o['owner_type'], 'owner_id' => $o['owner_id'],
                    'subject_type' => $type, 'subject_id' => $subject->id, 'basis' => 'legal_title', 'fraction' => $fractions[$i], 'title_form' => $form,
                    'capacity' => in_array($form, ['trust'], true) ? 'trustee' : 'individual', 'effective_from' => $from, 'evidence_document_id' => $evidence,
                    'created_by' => $this->ctx->user->id, 'updated_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
                ]);
            }
            Audit::log('ownership.title_set', $type, $subject->id, ['title_form' => $form, 'owners' => count($owners), 'effective_from' => $from], scopeId: $subject->scope_id);
            self::syncDerived($this->ctx->workspace->id, $type, $subject->id);
        });

        return $this->current($type, $subject->id);
    }

    private function subject(string $type, string $id, string $action): object
    {
        [$table, $domain] = self::SUBJECTS[$type] ?? throw ApiError::notFound();
        $row = Str::isUlid($id) ? $this->ctx->constrain(DB::table($table), $domain, $action)->where('id', $id)->whereNull('archived_at')->first() : null;
        if (! $row) {
            throw ApiError::notFound();
        }

        return $row;
    }

    private function spouses(array $ids): bool
    {
        [$a, $b] = array_values($ids);

        return DB::table('relationships')->where('workspace_id', $this->ctx->workspace->id)->whereIn('kind', ['spouse', 'domestic_partner'])->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->where(fn ($q) => $q->where(fn ($x) => $x->where('person_id', $a)->where('related_person_id', $b))->orWhere(fn ($x) => $x->where('person_id', $b)->where('related_person_id', $a)))
            ->exists();
    }

    private function equalShares(int $n): array
    {
        $share = bcdiv('1', (string) $n, 12);
        $out = array_fill(0, $n, $share);
        $out[$n - 1] = bcsub('1', bcmul($share, (string) ($n - 1), 12), 12);

        return array_map(fn ($v) => Dec::norm($v, 12), $out);
    }

    /** Display names only when the caller may see household identity data. */
    private function names(array $owners): array
    {
        $out = [];
        $p = array_column(array_filter($owners, fn ($o) => $o[0] === 'person'), 1);
        $e = array_column(array_filter($owners, fn ($o) => $o[0] === 'entity'), 1);
        foreach ($this->ctx->constrain(DB::table('persons'), 'household', 'read')->whereIn('id', $p ?: ['-'])->get(['id', 'display_name']) as $r) {
            $out['person:'.$r->id] = $r->display_name;
        }
        foreach ($this->ctx->constrain(DB::table('legal_entities'), 'governance', 'read')->whereIn('id', $e ?: ['-'])->get(['id', 'name']) as $r) {
            $out['entity:'.$r->id] = $r->name;
        }

        return $out;
    }
}
