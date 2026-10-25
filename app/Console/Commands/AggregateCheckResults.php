<?php

namespace App\Console\Commands;

use App\Models\CheckResult;
use App\Models\CheckStat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Hourly (routes/console.php): rolls the raw results of the last full hours
 * up into hour stats, and those into the stats of their days. Upserts, so
 * running it again (or over a longer period) changes nothing.
 */
class AggregateCheckResults extends Command
{
    protected $signature = 'checks:aggregate {--hours=3 : How many full hours back to (re)compute, at least}';

    protected $description = 'Roll raw check results up into hour and day stats';

    public function handle(): int
    {
        $currentHour = Date::now()->utc()->startOfHour();
        $days = [];

        for ($back = $this->hoursBack($currentHour); $back >= 1; $back--) {
            $hour = $currentHour->subHours($back);
            $this->aggregateHour($hour);
            $days[$hour->toDateString()] = $hour->startOfDay();
        }

        foreach ($days as $day) {
            $this->aggregateDay($day);
        }

        return self::SUCCESS;
    }

    /**
     * The asked hours, or back to the last hour that was rolled up when
     * that is longer ago (the scheduler or the database was down): a hole
     * in the hour stats would otherwise stay. Never past the raw results.
     */
    private function hoursBack(CarbonImmutable $currentHour): int
    {
        $asked = (int) $this->option('hours');
        $last = CheckStat::query()->where('granularity', CheckStat::HOUR)->max('period_start');

        if ($last === null) {
            return $asked;
        }

        // From the first result after it: hours without any (every check
        // paused) are no hole, and are not walked again every hour.
        $next = CheckResult::query()->where('checked_at', '>=', CarbonImmutable::parse($last, 'UTC')->addHour())->min('checked_at');

        if ($next === null) {
            return $asked;
        }

        $missed = (int) CarbonImmutable::parse($next, 'UTC')->startOfHour()->diffInHours($currentHour);

        return max($asked, min($missed, (int) config('herdbook.checks.keep_results_days') * 24));
    }

    private function aggregateHour(CarbonImmutable $hour): void
    {
        $rows = CheckResult::query()
            ->where('checked_at', '>=', $hour)
            ->where('checked_at', '<', $hour->addHour())
            ->get(['check_id', 'ok', 'response_ms'])
            ->groupBy('check_id')
            ->map(fn (Collection $results, int $checkId): array => [
                'check_id' => $checkId,
                'granularity' => CheckStat::HOUR,
                'period_start' => $hour,
                'up_count' => $results->where('ok', true)->count(),
                'down_count' => $results->where('ok', false)->count(),
                'avg_ms' => $this->round($results->whereNotNull('response_ms')->avg('response_ms')),
                'min_ms' => $results->whereNotNull('response_ms')->min('response_ms'),
                'max_ms' => $results->whereNotNull('response_ms')->max('response_ms'),
            ])
            ->values()
            ->all();

        $this->upsert($rows);
    }

    private function aggregateDay(CarbonImmutable $day): void
    {
        $rows = CheckStat::query()
            ->where('granularity', CheckStat::HOUR)
            ->where('period_start', '>=', $day)
            ->where('period_start', '<', $day->addDay())
            ->get()
            ->groupBy('check_id')
            ->map(function (Collection $hours, int $checkId) use ($day): array {
                $timed = $hours->whereNotNull('avg_ms');
                $weight = $timed->sum(fn (CheckStat $stat): int => $stat->up_count + $stat->down_count);

                return [
                    'check_id' => $checkId,
                    'granularity' => CheckStat::DAY,
                    'period_start' => $day,
                    'up_count' => $hours->sum('up_count'),
                    'down_count' => $hours->sum('down_count'),
                    'avg_ms' => $weight > 0
                        ? $this->round($timed->sum(fn (CheckStat $stat): int => $stat->avg_ms * ($stat->up_count + $stat->down_count)) / $weight)
                        : null,
                    'min_ms' => $hours->whereNotNull('min_ms')->min('min_ms'),
                    'max_ms' => $hours->whereNotNull('max_ms')->max('max_ms'),
                ];
            })
            ->values()
            ->all();

        $this->upsert($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        CheckStat::query()->upsert(
            $rows,
            ['check_id', 'granularity', 'period_start'],
            ['up_count', 'down_count', 'avg_ms', 'min_ms', 'max_ms'],
        );
    }

    private function round(int|float|null $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }
}
