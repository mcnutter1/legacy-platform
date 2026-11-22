<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Liveness of scheduled system tasks, shown on the Schedules page. Stores timing and outcome only, never task output. */
final class Heartbeat
{
    /** task => [label, cadence, description]; keep in sync with routes/console.php. */
    public const TASKS = [
        'work:interactive' => ['Interactive job worker', 'Every minute', 'Runs quick jobs: tax runs, projections, amortization.'],
        'work:bulk' => ['Bulk job worker', 'Every minute', 'Runs long jobs: data-source syncs, county records, document scans and extraction, reports.'],
        'tick:integrations' => ['Data-source scheduler', 'Every 15 minutes', 'Queues a sync for each approved connection that is due.'],
        'tick:outbox' => ['Outbox dispatch', 'Every minute', 'Delivers queued events to notification channels.'],
        'tick:notify' => ['Notification delivery', 'Every 2 minutes', 'Sends pending email notifications.'],
        'tick:alerts' => ['Due-date alerts', 'Daily 06:15 ET', 'Raises reminders for upcoming tax payments, renewals and deadlines.'],
        'tick:prune' => ['Housekeeping', 'Daily 03:40', 'Removes expired keys, tokens, sessions and finished jobs older than 30 days.'],
    ];

    public static function start(string $task): void
    {
        DB::table('scheduler_heartbeats')->upsert([['task' => $task, 'last_started_at' => now(), 'created_at' => now(), 'updated_at' => now()]], ['task'], ['last_started_at', 'updated_at']);
    }

    public static function finish(string $task, bool $ok): void
    {
        $started = DB::table('scheduler_heartbeats')->where('task', $task)->value('last_started_at');
        DB::table('scheduler_heartbeats')->where('task', $task)->update([
            'last_finished_at' => now(), 'last_status' => $ok ? 'succeeded' : 'failed', 'updated_at' => now(),
            'last_duration_ms' => $started ? max(0, (int) round((microtime(true) - strtotime((string) $started)) * 1000)) : null,
            'failures' => $ok ? 0 : DB::raw('failures + 1'),
        ]);
    }
}
