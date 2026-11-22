<?php

namespace App\Http\Controllers\Api;

use App\Resources\Registry;
use App\Resources\ResourceService;
use App\Services\Integrations\SourceBadges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Generic, policy-enforced REST over registry resources: /api/v1/workspaces/{w}/r/{type}[/{id}] */
class ResourceController extends ApiController
{
    private function svc(): ResourceService
    {
        return new ResourceService($this->ctx());
    }

    public function index(Request $r, string $type): JsonResponse
    {
        $svc = $this->svc();
        $def = $svc->definition($type);
        $page = $svc->list($type, $r->query());

        return response()->json([
            'data' => SourceBadges::attach($type, collect($page->items())->map(fn ($row) => $svc->present($def, $row, $r->boolean('full')))->all()),
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode(),
        ]);
    }

    public function show(string $type, string $id): JsonResponse
    {
        $svc = $this->svc();
        $def = $svc->definition($type);
        $row = $svc->find($type, $id);

        return $this->ok(SourceBadges::attach($type, [$svc->present($def, $row)])[0], 200, ['ETag' => '"'.$row->edit_version.'"']);
    }

    public function store(Request $r, string $type): JsonResponse
    {
        $svc = $this->svc();
        $row = $svc->create($type, $r->json()->all() ?: $r->all());

        return $this->ok($svc->present($svc->definition($type), $row), 201, ['ETag' => '"'.$row->edit_version.'"']);
    }

    public function update(Request $r, string $type, string $id): JsonResponse
    {
        $svc = $this->svc();
        $row = $svc->update($type, $id, $r->json()->all() ?: $r->all(), $this->ifMatch($r));

        return $this->ok($svc->present($svc->definition($type), $row), 200, ['ETag' => '"'.$row->edit_version.'"']);
    }

    public function destroy(Request $r, string $type, string $id): JsonResponse
    {
        $this->svc()->archive($type, $id, $this->ifMatch($r));

        return $this->ok(['archived' => true]);
    }

    /** Resource schema for generated clients and generic forms. */
    public static function meta(): array
    {
        $out = [];
        foreach (Registry::all() as $type => $def) {
            $fields = [];
            foreach ($def['fields'] as $name => $spec) {
                $f = Registry::field($spec);
                $fields[] = ['name' => $name, 'type' => $f['type'], 'required' => $f['required'], 'readonly' => $f['readonly'] || ($def['readonly'] ?? false),
                    'values' => $f['values'], 'ref' => $f['ref'], 'poly_type_field' => $def['poly'][$name] ?? null, 'sensitive' => $def['sensitive'][$name] ?? null,
                    'derived_from' => $def['derived_from'][$name] ?? null, 'required_without' => $def['required_without'][$name] ?? null];
            }
            $out[$type] = ['type' => $type, 'label' => $def['label'], 'domain' => $def['domain'], 'title' => $def['title'] ?? null, 'list' => $def['list'] ?? [],
                'readonly' => (bool) ($def['readonly'] ?? false), 'creatable' => ! ($def['readonly'] ?? false) && ! ($def['no_create'] ?? false),
                'parent' => $def['parent'] ?? null, 'unscoped' => (bool) ($def['unscoped'] ?? false), 'searchable' => ! empty($def['search']), 'date' => $def['date'] ?? null,
                'fields' => $fields];
        }

        return $out;
    }
}
