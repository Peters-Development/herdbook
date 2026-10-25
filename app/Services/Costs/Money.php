<?php

namespace App\Services\Costs;

use App\Models\Contracts\Renewable;
use BcMath\Number;

/**
 * Amounts as they read in the active language (lang/{locale}/formats.php):
 * "€1,234.56" in English, "€ 1.234,56" in Dutch. From the decimal string,
 * not through a float.
 */
final class Money
{
    public static function format(Number|string $amount, int $decimals = 2): string
    {
        // Amounts are never negative (the forms say so).
        [$whole, $fraction] = array_pad(explode('.', (string) (new Number((string) $amount))->round($decimals), 2), 2, '');

        // Groups of three from the right; the separator is not reversed, it may be more than one byte.
        $groups = array_map(strrev(...), array_reverse(str_split(strrev($whole), 3)));
        $number = implode(__('formats.thousands_separator'), $groups)
            .($decimals > 0 ? __('formats.decimal_separator').str_pad($fraction, $decimals, '0') : '');

        return __('formats.money', ['symbol' => config('herdbook.currency_symbol'), 'amount' => $number]);
    }

    /**
     * @param  iterable<Number>  $amounts
     */
    public static function sum(iterable $amounts): Number
    {
        $total = new Number(0);

        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * "€9.95 per year", as it was entered; null without an amount.
     */
    public static function describe(Renewable $item): ?string
    {
        if ($item->cost_amount === null) {
            return null;
        }

        return trim(self::format($item->cost_amount).' '.$item->cost_period?->getLabel());
    }
}
