<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
| The scheduler container runs `schedule:work`; the checks themselves run on
| the `checks` queue. Overlap locks expire well before the next run: a run killed
| mid-way (a container stop) must not block the next ones for a day.
|
| The scheduler sends a command's output to /dev/null by default, and with
| LOG_CHANNEL=stderr its log lines with it: each event appends to the
| container's own output instead, so the container log of the scheduler has them.
*/

$events = [
    Schedule::command('checks:dispatch')->everyMinute()->withoutOverlapping(5),
    Schedule::command('checks:notify')->everyMinute()->withoutOverlapping(5),
    Schedule::command('checks:incidents')->everyMinute()->withoutOverlapping(5),
    Schedule::command('checks:aggregate')->hourlyAt(5)->withoutOverlapping(55),
    Schedule::command('model:prune')->dailyAt('04:15'),
    Schedule::command('backups:check')->dailyAt('09:00')->timezone(config('herdbook.display_timezone')),
    Schedule::command('heartbeat')->everyFiveMinutes(),
    Schedule::command('renewals:digest')->weekdays()->at('08:00')->timezone(config('herdbook.display_timezone')),
];

if (is_writable('/proc/1/fd/1')) {
    array_walk($events, fn (Event $event) => $event->appendOutputTo('/proc/1/fd/1'));
}
