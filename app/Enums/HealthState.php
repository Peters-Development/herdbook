<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a project (its production) or an environment is doing, derived from
 * its checks (ProjectHealth). From bad to good; not stored.
 */
enum HealthState: string implements HasColor, HasLabel
{
    /** An availability check (HTTP, keyword, TCP, ping) is down. */
    case Down = 'down';

    /** A certificate or DNS check is down, or a check is in doubt or has no recent result. */
    case Degraded = 'degraded';

    case Up = 'up';

    /** There are active checks, but none has run yet. */
    case Unknown = 'unknown';

    /** There are checks, but all of them are paused. */
    case Paused = 'paused';

    /** There are no checks at all: a gap, unless it is deliberate (Unmonitored). */
    case Unwatched = 'unwatched';

    /** There are no checks, and the project says it needs none. */
    case Unmonitored = 'unmonitored';

    public function getLabel(): string
    {
        return match ($this) {
            self::Down => __('Outage'),
            self::Degraded => __('Needs attention'),
            self::Up => __('Healthy'),
            self::Unknown => __('No data yet'),
            self::Paused => __('Paused'),
            self::Unwatched => __('Not monitored'),
            self::Unmonitored => __('No monitoring needed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Down => 'danger',
            self::Degraded => 'warning',
            self::Up => 'success',
            self::Unknown, self::Paused, self::Unwatched, self::Unmonitored => 'gray',
        };
    }
}
