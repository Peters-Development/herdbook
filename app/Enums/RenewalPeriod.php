<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The terms a renewal can have, in months (renewal_period_months).
 */
enum RenewalPeriod: int implements HasLabel
{
    case OneMonth = 1;
    case ThreeMonths = 3;
    case SixMonths = 6;
    case OneYear = 12;
    case TwoYears = 24;
    case ThreeYears = 36;

    public function getLabel(): string
    {
        return self::label($this->value);
    }

    /**
     * "1 month", "6 months", "1 year", "2 years"; also for a term that is
     * not one of the cases.
     */
    public static function label(int $months): string
    {
        return $months % 12 === 0
            ? trans_choice(':count year|:count years', intdiv($months, 12))
            : trans_choice(':count month|:count months', $months);
    }
}
