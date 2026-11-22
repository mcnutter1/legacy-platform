<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Transactional outbox: call inside the same DB transaction as the mutation it describes. */
final class Outbox
{
    public static function publish(string $type, array $payload, ?string $aggregateType = null, ?string $aggregateId = null, ?string $workspaceId = null): string
    {
        $id = (string) Str::ulid();
        DB::table('outbox_events')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId ?? AccessContext::current()?->workspace->id,
            'type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => json_encode($payload),
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
        ]);

        return $id;
    }
}
