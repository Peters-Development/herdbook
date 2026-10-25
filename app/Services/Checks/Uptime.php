<?php

namespace App\Services\Checks;

use App\Models\Check;
use App\Models\CheckResult;
use App\Models\CheckStat;
use Illuminate\Support\Facades\Date;

/**
 * Uptime as a percentage: the last day from the raw results, longer periods
 * from the hour stats.
 */
final class Uptime
{
    public function lastDay(Check $check): ?float
    {
        $results = CheckResult::query()
            ->where('check_id', $check->id)
            ->where('checked_at', '>=', Date::now()->subDay())
            ->selectRaw('count(*) as total, sum(case when ok then 1 else 0 end) as up')
            ->first();

        return $this->percentage((int) $results?->up, (int) $results?->total);
    }

    public function lastDays(Check $check, int $days): ?float
    {
        $stats = CheckStat::query()
            ->where('check_id', $check->id)
            ->where('granularity', CheckStat::HOUR)
            ->where('period_start', '>=', Date::now()->subDays($days))
            ->selectRaw('sum(up_count) as up, sum(down_count) as down')
            ->first();

        return $this->percentage((int) $stats?->up, (int) $stats?->up + (int) $stats?->down);
    }

    private function percentage(int $up, int $total): ?float
    {
        return $total === 0 ? null : round($up / $total * 100, 2);
    }
}
