<?php

namespace App\Services\Checks;

use App\Models\CheckResult;
use App\Models\CheckStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Up and down counts per check and clock hour, for the hour bars. Hours up
 * to the last one `checks:aggregate` rolled up come from the hour stats;
 * the ones after it (the running hour, and the previous one before :05)
 * from the raw results.
 */
final class CheckHistory
{
    /**
     * The first of the $hours clock hours (UTC) that end with the running one.
     */
    public static function firstHour(int $hours = 24): CarbonImmutable
    {
        return Date::now()->utc()->startOfHour()->subHours($hours - 1);
    }

    /**
     * @param  list<int>  $checkIds
     * @param  CarbonImmutable|null  $first  the first hour (firstHour()), so a caller labels the same hours
     * @param  bool  $withResponse  also "ms": the average response time of that hour (null without one)
     * @return array<int, list<array{up: int, down: int, ms?: ?int}>> check id => one entry per hour, oldest first, the running hour last
     */
    public function hourly(array $checkIds, int $hours = 24, ?CarbonImmutable $first = null, bool $withResponse = false): array
    {
        if ($checkIds === []) {
            return [];
        }

        $first ??= self::firstHour($hours);
        $current = $first->addHours($hours - 1);
        $entry = fn (int $up, int $down, mixed $ms): array => ['up' => $up, 'down' => $down] + ($withResponse ? ['ms' => $ms === null ? null : (int) round((float) $ms)] : []);
        $history = array_fill_keys($checkIds, array_fill(0, $hours, $entry(0, 0, null)));

        // Thousands of rows on a busy host: plain rows, no models.
        $stats = CheckStat::query()
            ->whereIn('check_id', $checkIds)
            ->where('granularity', CheckStat::HOUR)
            ->where('period_start', '>=', $first)
            ->where('period_start', '<', $current)
            ->toBase()
            ->get(['check_id', 'period_start', 'up_count', 'down_count', 'avg_ms']);

        // Up to 90 days of rows: whole seconds, not a Carbon per row.
        foreach ($stats as $stat) {
            $index = $this->index($first, strtotime($stat->period_start.' UTC'));
            $history[(int) $stat->check_id][$index] = $entry((int) $stat->up_count, (int) $stat->down_count, $stat->avg_ms);
        }

        // Hours are rolled up for all checks at once: past the last hour with
        // a stat (of any check), nothing is. Raw results go back only so far,
        // and only from the first one these checks have: a check paused for a
        // month needs no query per hour of that month.
        $rolledUp = CheckStat::query()->where('granularity', CheckStat::HOUR)->max('period_start');
        $fromIndex = max(
            0,
            $rolledUp === null ? 0 : $this->index($first, strtotime($rolledUp.' UTC')) + 1,
            $this->index($first, Date::now()->utc()->startOfHour()->subDays((int) config('herdbook.checks.keep_results_days'))->getTimestamp()),
        );

        $firstResult = $fromIndex < $hours
            ? CheckResult::query()->whereIn('check_id', $checkIds)->where('checked_at', '>=', $first->addHours($fromIndex))->min('checked_at')
            : null;

        if ($firstResult === null) {
            return $history;
        }

        $fromIndex = max($fromIndex, $this->index($first, strtotime($firstResult.' UTC')));

        for ($index = $fromIndex; $index < $hours; $index++) {
            $hour = $first->addHours($index);

            $counts = CheckResult::query()
                ->whereIn('check_id', $checkIds)
                ->where('checked_at', '>=', $hour)
                ->where('checked_at', '<', $hour->addHour())
                ->groupBy('check_id')
                // The same average as the hour stats: of the results with a time.
                ->selectRaw('check_id, count(*) as total, sum(case when ok then 1 else 0 end) as up, avg(response_ms) as ms')
                ->toBase()
                ->get();

            foreach ($counts as $count) {
                $history[(int) $count->check_id][$index] = $entry((int) $count->up, (int) $count->total - (int) $count->up, $count->ms);
            }
        }

        return $history;
    }

    /**
     * The average response time of the good results in the last hour: one
     * measurement says little, it jumps from minute to minute.
     *
     * @param  list<int>  $checkIds
     * @return array<int, int> check id => milliseconds (checks without good results are left out)
     */
    public function responseTimes(array $checkIds): array
    {
        if ($checkIds === []) {
            return [];
        }

        return CheckResult::query()
            ->whereIn('check_id', $checkIds)
            ->where('checked_at', '>=', Date::now()->subHour())
            ->where('ok', true)
            ->whereNotNull('response_ms')
            ->groupBy('check_id')
            ->selectRaw('check_id, avg(response_ms) as average')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->check_id => (int) round((float) $row->average)])
            ->all();
    }

    /**
     * Per hour the share of good results of the worst of these checks (null:
     * none had results): a site down for an hour next to a ping that answered
     * is a bad hour, not half a good one. The bars of the tile and of the
     * status page.
     *
     * @param  array<int, list<array{up: int, down: int}>>  $history  hourly()
     * @param  iterable<int>  $checkIds
     * @return list<?float>
     */
    public static function worstPerHour(array $history, iterable $checkIds, int $hours): array
    {
        $worst = array_fill(0, $hours, null);

        foreach ($checkIds as $checkId) {
            foreach ($history[$checkId] ?? [] as $index => $counts) {
                $total = $counts['up'] + $counts['down'];

                if ($total > 0) {
                    $worst[$index] = min($worst[$index] ?? 1.0, (float) ($counts['up'] / $total));
                }
            }
        }

        return $worst;
    }

    /**
     * The hour a moment (a Unix timestamp) falls in, counted from $first.
     */
    private function index(CarbonImmutable $first, int $moment): int
    {
        return intdiv($moment - $first->getTimestamp(), 3600);
    }
}
