<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which payments the cost page lists: calendar months from this one on.
 */
enum CostRange: string implements HasLabel
{
    case Month = 'month';
    case Quarter = 'quarter';
    case TwelveMonths = '12-months';

    public function getLabel(): string
    {
        return match ($this) {
            self::Month => __('This month'),
            self::Quarter => __('This quarter'),
            self::TwelveMonths => __('Next 12 months'),
        };
    }
}
