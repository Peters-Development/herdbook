<?php

/*
| How dates, times and amounts read in this language. Date formats are PHP
| date() formats, unless the key says "iso" (Carbon's isoFormat(), which
| translates day and month names).
*/

return [
    'date' => 'd-m-Y',
    'datetime' => 'd-m-Y H:i',
    'day_month' => 'd-m',
    'day_month_time' => 'd-m H:i',
    'day_month_time_zone' => 'd-m H:i T',
    'iso_weekday_day_month' => 'dd DD-MM',

    // For JavaScript's Intl (the status screen): its locale, and a day with its month.
    'intl_locale' => 'nl-NL',
    'intl_day_month' => ['day' => '2-digit', 'month' => '2-digit'],

    'decimal_separator' => ',',
    'thousands_separator' => '.',
    // :symbol is the currency symbol, :amount the number.
    'money' => ':symbol :amount',
];
