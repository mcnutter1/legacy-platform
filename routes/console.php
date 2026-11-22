<?php

use App\Support\Heartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

$beat = fn (Event $e, string $task) => $e->before(fn () => Heartbeat::start($task))
    ->onSuccess(fn () => Heartbeat::finish($task, true))->onFailure(fn () => Heartbeat::finish($task, false));

// Bounded workers by priority; overlap locks make each queue effectively continuous without a daemon.
$beat(Schedule::command('legacy:work interactive')->everyMinute()->withoutOverlapping(3)->runInBackground(), 'work:interactive');
$beat(Schedule::command('legacy:work bulk')->everyMinute()->withoutOverlapping(4)->runInBackground(), 'work:bulk');
$beat(Schedule::command('legacy:tick outbox')->everyMinute()->withoutOverlapping(2), 'tick:outbox');
$beat(Schedule::command('legacy:tick notify')->everyTwoMinutes()->withoutOverlapping(3), 'tick:notify');
$beat(Schedule::command('legacy:tick alerts')->dailyAt('06:15')->timezone('America/New_York')->withoutOverlapping(30), 'tick:alerts');
$beat(Schedule::command('legacy:tick prune')->dailyAt('03:40')->withoutOverlapping(30), 'tick:prune');
$beat(Schedule::command('legacy:tick integrations')->everyFifteenMinutes()->withoutOverlapping(30), 'tick:integrations');
