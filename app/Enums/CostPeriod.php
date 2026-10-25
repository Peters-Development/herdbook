<?php

namespace App\Enums;

use BcMath\Number;
use Filament\Support\Contracts\HasLabel;

/**
 * What an amount is for. A monthly amount is paid every month, a yearly one
 * at every renewal (RecurringCosts).
 */
enum CostPeriod: string implements HasLabel
{
    case Month = 'month';
    case Year = 'year';

    public function getLabel(): string
    {
        return match ($this) {
            self::Month => __('per month'),
            self::Year => __('per year'),
        };
    }

    public function perYear(Number $amount): Number
    {
        return match ($this) {
            self::Month => $amount * 12,
            self::Year => $amount,
        };
    }
}
