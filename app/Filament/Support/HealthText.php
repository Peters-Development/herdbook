<?php

namespace App\Filament\Support;

use App\Enums\CheckState;
use App\Models\Check;
use App\Services\Health\HealthReport;
use Carbon\CarbonInterface;

/**
 * How a project's state reads on the dashboard tile and its status page.
 */
final class HealthText
{
    /**
     * "storage-1 · Ping 203.0.113.11: Timed out": the check, and why it is
     * a problem when it has no error of its own.
     */
    public static function problem(Check $check): string
    {
        $reason = match (true) {
            $check->isStale() => __('no recent data'),
            filled($check->last_error) => $check->last_error,
            $check->state === CheckState::Pending => __('possibly down'),
            default => null,
        };

        return $reason === null ? $check->nameWithServer() : "{$check->nameWithServer()}: {$reason}";
    }

    /**
     * "Since 14:32 (12m)".
     */
    public static function since(HealthReport $health): ?string
    {
        if ($health->since === null) {
            return null;
        }

        return __('Since :time (:duration)', [
            'time' => LocalTime::format($health->since),
            'duration' => $health->since->diffForHumans(short: true, syntax: CarbonInterface::DIFF_ABSOLUTE),
        ]);
    }
}
