<?php

/*
| How dates, times and amounts read in this language. Date formats are PHP
| date() formats, unless the key says "iso" (Carbon's isoFormat(), which
| translates day and month names).
*/

return [
    'date' => 'j M Y',
    'datetime' => 'j M Y H:i',
    'day_month' => 'j M',
    'day_month_time' => 'j M H:i',
    'day_month_time_zone' => 'j M H:i T',
    'iso_weekday_day_month' => 'ddd D MMM',

    // For JavaScript's Intl (the status screen): its locale, and a day with its month.
    'intl_locale' => 'en-GB',
    'intl_day_month' => ['day' => 'numeric', 'month' => 'short'],

    'decimal_separator' => '.',
    'thousands_separator' => ',',
    // :symbol is the currency symbol, :amount the number.
    'money' => ':symbol:amount',
];
