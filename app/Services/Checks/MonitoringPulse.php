<?php

namespace App\Services\Checks;

use App\Models\Check;
use App\Models\CheckResult;
use Carbon\CarbonImmutable;

/**
 * "Do the checks still run?" Active checks without a fresh result mean a
 * stuck queue, a stopped scheduler or a broken deploy: their states are old
 * news. Shared by the heartbeat, the dashboard and the status screen.
 */
final class MonitoringPulse
{
    public const int FRESH_MINUTES = 5;

    public function lastResultAt(): ?CarbonImmutable
    {
        $last = CheckResult::query()->max('checked_at');

        return $last === null ? null : CarbonImmutable::parse($last, 'UTC');
    }

    /**
     * No result within the window: five minutes, or longer when even the
     * most frequent active check runs less often (every 15 minutes, hourly).
     */
    public function isStalled(): bool
    {
        $shortest = Check::query()->where('enabled', true)->min('interval_seconds');

        if ($shortest === null) {
            return false;
        }

        $window = max(self::FRESH_MINUTES * 60, (int) $shortest + 120);

        return ! CheckResult::query()->where('checked_at', '>=', now()->subSeconds($window))->exists();
    }
}
