<?php

namespace App\Filament\Support;

use Carbon\CarbonInterface;

/**
 * A moment as the dashboard says it, in the display timezone and the user's
 * language: "14:32" today, "yesterday at 14:32", "21 Oct 14:32". Lower case:
 * it usually sits mid-sentence.
 */
final class LocalTime
{
    public static function format(CarbonInterface $moment, bool $withDay = false): string
    {
        $timezone = config('herdbook.display_timezone');
        $local = $moment->timezone($timezone);
        $today = now($timezone)->startOfDay();
        $time = $local->format('H:i');

        return match (true) {
            $local->isSameDay($today) => $withDay ? __('today at :time', ['time' => $time]) : $time,
            $local->isSameDay($today->subDay()) => __('yesterday at :time', ['time' => $time]),
            default => $local->translatedFormat(__('formats.day_month_time')),
        };
    }
}
