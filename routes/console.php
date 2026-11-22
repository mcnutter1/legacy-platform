<?php

use Illuminate\Support\Facades\Schedule;

// Bounded workers by priority; overlap locks make each queue effectively continuous without a daemon.
Schedule::command('legacy:work interactive')->everyMinute()->withoutOverlapping(3)->runInBackground();
Schedule::command('legacy:work bulk')->everyMinute()->withoutOverlapping(4)->runInBackground();
Schedule::command('legacy:tick outbox')->everyMinute()->withoutOverlapping(2);
Schedule::command('legacy:tick notify')->everyTwoMinutes()->withoutOverlapping(3);
Schedule::command('legacy:tick alerts')->dailyAt('06:15')->timezone('America/New_York')->withoutOverlapping(30);
Schedule::command('legacy:tick prune')->dailyAt('03:40')->withoutOverlapping(30);
Schedule::command('legacy:tick integrations')->hourlyAt(17)->withoutOverlapping(30);
