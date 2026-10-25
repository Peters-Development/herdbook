<?php

namespace App\Services\Health;

use App\Enums\HealthState;
use App\Models\Check;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How a project's production, or one environment, is doing (ProjectHealth).
 */
final readonly class HealthReport
{
    /**
     * @param  Collection<int, Check>  $problems  the checks behind Down or Degraded, worst first
     * @param  list<?float>  $hours  share of good results per clock hour, oldest first (null: no results)
     * @param  CarbonImmutable  $firstHour  the start (UTC) of the first of those hours
     */
    public function __construct(
        public HealthState $state,
        public ?CarbonImmutable $since,
        public Collection $problems,
        public ?int $responseMs,
        public array $hours,
        public ?float $uptime,
        public CarbonImmutable $firstHour,
    ) {}

    /**
     * One tone per hour bar: a blip of one failure in an hour of minute
     * checks still reads as good.
     *
     * @return list<'ok'|'warn'|'bad'|'none'>
     */
    public function hourTones(): array
    {
        return array_map(self::tone(...), $this->hours);
    }

    /**
     * The tone of one bar: its share of good results (null: no results).
     *
     * @return 'ok'|'warn'|'bad'|'none'
     */
    public static function tone(?float $share): string
    {
        return match (true) {
            $share === null => 'none',
            $share >= 0.98 => 'ok',
            $share >= 0.5 => 'warn',
            default => 'bad',
        };
    }
}
