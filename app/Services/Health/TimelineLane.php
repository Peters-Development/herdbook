<?php

namespace App\Services\Health;

use App\Models\Check;

/**
 * One check on a project's status page: its results per bar.
 */
final readonly class TimelineLane
{
    /**
     * @param  list<array{up: int, down: int}>  $counts  one entry per bar of the timeline
     * @param  list<?float>  $worst  per bar the share of its worst hour (null: no results), for its tone
     */
    public function __construct(
        public Check $check,
        public array $counts,
        public array $worst,
    ) {}

    /**
     * The share of good results in one bar (null: no results).
     */
    public function share(int $index): ?float
    {
        $total = $this->counts[$index]['up'] + $this->counts[$index]['down'];

        return $total === 0 ? null : (float) ($this->counts[$index]['up'] / $total);
    }

    /**
     * The share of good results over the whole range, in percent.
     */
    public function uptime(): ?float
    {
        $up = array_sum(array_column($this->counts, 'up'));
        $total = $up + array_sum(array_column($this->counts, 'down'));

        return $total === 0 ? null : round($up / $total * 100, 2);
    }

    public function label(): string
    {
        return $this->check->nameWithServer();
    }
}
