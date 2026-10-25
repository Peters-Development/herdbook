<?php

namespace App\Filament\Support;

/**
 * A percentage (uptime) as the language writes it: "99.95%", "99,95%".
 */
final class Percentage
{
    public static function format(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, __('formats.decimal_separator'), __('formats.thousands_separator')).'%';
    }
}
