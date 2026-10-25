<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How far back a project's status page looks, and how it cuts that time
 * into bars: clock hours up to a week, calendar days (in the display
 * timezone) beyond. The hour stats are kept 90 days, so that is the limit.
 */
enum TimelineRange: string implements HasLabel
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';
    case Quarter = '90d';

    public function getLabel(): string
    {
        return match ($this) {
            self::Day => trans_choice(':count hour|:count hours', 24),
            self::Week => trans_choice(':count day|:count days', 7),
            self::Month => trans_choice(':count day|:count days', 30),
            self::Quarter => trans_choice(':count day|:count days', 90),
        };
    }

    /** One bar per day instead of per hour. */
    public function daily(): bool
    {
        return in_array($this, [self::Month, self::Quarter], true);
    }

    /** How many bars. */
    public function buckets(): int
    {
        return match ($this) {
            self::Day => 24,
            self::Week => 24 * 7,
            self::Month => 30,
            self::Quarter => 90,
        };
    }
}
