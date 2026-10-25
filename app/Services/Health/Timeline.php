<?php

namespace App\Services\Health;

use App\Enums\TimelineRange;
use App\Models\CheckEvent;
use App\Models\Incident;
use App\Models\Maintenance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A project's production over a range (ProjectTimeline): bars, the checks
 * behind them, and what happened meanwhile. Moments are UTC; labels are in
 * the display timezone.
 */
final readonly class Timeline
{
    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string, share: ?float, worst: ?float, ms: ?int}>  $buckets  oldest first; share: the average of its hours (the worst active availability check of each), worst: its worst hour
     * @param  ?float  $uptime  the average of the hours, in percent
     * @param  list<TimelineLane>  $lanes
     * @param  Collection<int, Maintenance>  $maintenance  not called off, overlapping the range
     * @param  Collection<int, Incident>  $incidents  overlapping the range
     * @param  Collection<int, CheckEvent>  $events  changes between up and down, newest first
     */
    public function __construct(
        public TimelineRange $range,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public array $buckets,
        public ?float $uptime,
        public ?int $responseMs,
        public array $lanes,
        public Collection $maintenance,
        public Collection $incidents,
        public Collection $events,
    ) {}

    /**
     * Where a moment falls, in percent of the width (clamped to the range).
     */
    public function position(CarbonInterface $moment): float
    {
        $span = $this->start->diffInSeconds($this->end);
        $offset = $this->start->diffInSeconds($moment, absolute: false);

        return round(max(0, min(100, $offset / $span * 100)), 3);
    }

    /**
     * The left edge and width of a period, in percent; a period without an
     * end (or a moment) gets no width, the view gives it a minimum.
     *
     * @return array{left: float, width: float}
     */
    public function span(CarbonInterface $from, ?CarbonInterface $to): array
    {
        $left = $this->position($from);

        return ['left' => $left, 'width' => $to === null ? 0.0 : max(0.0, $this->position($to) - $left)];
    }

    /**
     * The bar a moment falls in, if any.
     */
    public function bucketAt(CarbonInterface $moment): ?int
    {
        foreach ($this->buckets as $index => $bucket) {
            if ($moment >= $bucket['start'] && $moment < $bucket['end']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * "23 Oct 13:00–14:00", or "Wed 21 Oct" for a day.
     */
    public function label(int $index): string
    {
        return $this->buckets[$index]['label'];
    }

    /**
     * Labels under the bars: every three hours for a day, every day for a
     * week, every Monday for a month, every other Monday for a quarter. One
     * near the right edge is aligned to it ("end"), so it stays inside; every
     * other one ("minor") makes way on a narrow screen.
     *
     * @return list<array{left: float, label: string, end: bool, minor: bool}>
     */
    public function ticks(): array
    {
        $ticks = [];
        $timezone = config('herdbook.display_timezone');

        foreach ($this->buckets as $bucket) {
            $local = $bucket['start']->timezone($timezone);

            $label = match ($this->range) {
                TimelineRange::Day => $local->hour % 3 === 0 ? $local->format('H:i') : null,
                TimelineRange::Week => $local->hour === 0 ? $local->isoFormat(__('formats.iso_weekday_day_month')) : null,
                TimelineRange::Month => $local->isMonday() ? $local->translatedFormat(__('formats.day_month')) : null,
                TimelineRange::Quarter => $local->isMonday() && $local->weekOfYear % 2 === 0 ? $local->translatedFormat(__('formats.day_month')) : null,
            };

            if ($label !== null) {
                $left = $this->position($bucket['start']);
                // One aligned to the right edge reaches back over its neighbour: it makes way too.
                $ticks[] = ['left' => $left, 'label' => $label, 'end' => $left > 92, 'minor' => count($ticks) % 2 === 1 || $left > 92];
            }
        }

        return $ticks;
    }

    /**
     * The top of the response time chart: a round number with room above
     * the highest bar (null: no times at all).
     */
    public function scaleMs(): ?int
    {
        $values = array_filter(array_column($this->buckets, 'ms'), fn (?int $ms): bool => $ms !== null);

        if ($values === []) {
            return null;
        }

        $top = max(max($values) * 1.25, 10);
        $step = match (true) {
            $top <= 100 => 20,
            $top <= 500 => 100,
            $top <= 2000 => 250,
            default => 1000,
        };

        return (int) (ceil($top / $step) * $step);
    }

    /**
     * Where a band (maintenance, an incident) goes. One near the right edge
     * is anchored there and grows to the left, so it stays in view.
     *
     * @return array{style: string, flip: bool}
     */
    public function band(CarbonInterface $from, ?CarbonInterface $to): array
    {
        ['left' => $left, 'width' => $width] = $this->span($from, $to);
        $flip = $left > 75;

        return [
            'style' => ($flip ? 'right: '.round(100 - $left - $width, 3).'%' : "left: {$left}%")."; width: {$width}%",
            'flip' => $flip,
        ];
    }

    /**
     * The response time as polylines in a box of one unit per bar by 100,
     * broken where a bar has no time (y 0 is the top).
     *
     * @return list<string> "x,y x,y …" per unbroken stretch
     */
    public function responseSegments(): array
    {
        $max = $this->scaleMs();
        $segments = [];
        $points = [];

        foreach ($this->buckets as $index => $bucket) {
            if ($max === null || $bucket['ms'] === null) {
                $segments[] = $points;
                $points = [];

                continue;
            }

            $points[] = [$index + 0.5, round(100 - $bucket['ms'] / $max * 100, 2)];
        }

        $segments[] = $points;

        // A lone point is a polyline that draws nothing: a short dash instead.
        return array_values(array_map(fn (array $points): string => implode(' ', array_map(
            fn (array $point): string => "{$point[0]},{$point[1]}",
            count($points) === 1 ? [[$points[0][0] - 0.3, $points[0][1]], [$points[0][0] + 0.3, $points[0][1]]] : $points,
        )), array_filter($segments)));
    }
}
