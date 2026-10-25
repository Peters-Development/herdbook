<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who hears about a check going down or up again.
 */
enum AlertLevel: string implements HasLabel
{
    /** Pushover first, with emergency priority: wakes you up at night (AlertChannels). */
    case Critical = 'critical';

    /** ntfy first: a normal notification (AlertChannels). */
    case Normal = 'normal';

    /** Only visible in the panel. */
    case Silent = 'silent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Critical => __('Critical (wakes you up via Pushover)'),
            self::Normal => __('Normal'),
            self::Silent => __('Silent (only shown in Herdbook)'),
        };
    }
}
