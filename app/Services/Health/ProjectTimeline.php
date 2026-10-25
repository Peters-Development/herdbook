<?php

namespace App\Services\Health;

use App\Enums\IncidentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\TimelineRange;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Project;
use App\Services\Checks\CheckHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * A project's production over a range, for its status page: the same checks
 * and the same bars as the tile (ProjectHealth: the worst active
 * availability check per hour), over hours or days, with the maintenance,
 * incidents and changes of that time. The maintenance and incidents have the
 * reach of ProjectIncidents: the project, or a server its production needs;
 * incidents also the network such a server stands in.
 */
final class ProjectTimeline
{
    /** Changes between up and down for the range, or for one bar: enough for a bad quarter. */
    public const int EVENT_LIMIT = 200;

    public function __construct(
        private readonly ProjectHealth $health,
        private readonly CheckHistory $history,
    ) {}

    public function build(Project $project, TimelineRange $range): Timeline
    {
        $buckets = $this->buckets($range);
        $start = $buckets[0]['start'];
        $end = $buckets[array_key_last($buckets)]['end'];

        // Availability first, as on the tile; then by name.
        $checks = $this->health->productionChecks($project)
            ->sortBy(fn (Check $check): string => ($check->type->measuresAvailability() ? '0' : '1').mb_strtolower($check->name));

        // Up to and including the running hour.
        $hours = (int) round($start->diffInHours(Date::now()->utc()->startOfHour())) + 1;
        $history = $this->history->hourly($checks->keys()->all(), $hours, $start, withResponse: true);
        $bucketOfHour = $this->bucketOfHour($buckets, $start, $hours);

        // The bars count what the tile counts: active availability checks.
        $counted = $checks->filter(fn (Check $check): bool => $check->enabled && $check->type->measuresAvailability());
        $worst = CheckHistory::worstPerHour($history, $counted->keys(), $hours);

        $lanes = [];
        $ms = [];

        foreach ($checks as $check) {
            $lanes[] = $this->lane($check, $history[$check->id] ?? [], $bucketOfHour, count($buckets));

            if ($check->timesThePage()) {
                foreach ($this->timesPerBucket($history[$check->id] ?? [], $bucketOfHour) as $index => $value) {
                    $ms[$index][] = $value;
                }
            }
        }

        $shares = [];

        foreach ($worst as $hour => $share) {
            if ($share !== null && isset($bucketOfHour[$hour])) {
                $shares[$bucketOfHour[$hour]][] = $share;
            }
        }

        foreach ($buckets as $index => $bucket) {
            $buckets[$index]['share'] = isset($shares[$index]) ? array_sum($shares[$index]) / count($shares[$index]) : null;
            $buckets[$index]['worst'] = isset($shares[$index]) ? min($shares[$index]) : null;
            $buckets[$index]['ms'] = isset($ms[$index]) ? (int) round(array_sum($ms[$index]) / count($ms[$index])) : null;
        }

        // The uptime is the average of the hours, as on the tile: the same
        // over every range, and a running hour or day weighs what it lasted.
        $measured = array_values(array_filter($worst, fn (?float $share): bool => $share !== null));
        $times = array_filter(array_column($buckets, 'ms'), fn (?int $value): bool => $value !== null);

        return new Timeline(
            range: $range,
            start: $start,
            end: $end,
            buckets: $buckets,
            uptime: $measured === [] ? null : round(array_sum($measured) / count($measured) * 100, 2),
            responseMs: $times === [] ? null : (int) round(array_sum($times) / count($times)),
            lanes: $lanes,
            maintenance: $this->maintenance($project, $start, $end),
            incidents: $this->incidents($project, $start, $end),
            events: $this->events($checks->keys()->all(), $start, $end),
        );
    }

    /**
     * Changes between up and down of the timeline's checks, newest first.
     * For one bar its own, not what is left of the range's limit.
     *
     * @param  list<int>  $checkIds
     * @return Collection<int, CheckEvent>
     */
    public function events(array $checkIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return CheckEvent::query()
            ->with('check.server:id,name')
            ->whereIn('check_id', $checkIds)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->latest('occurred_at')
            ->limit(self::EVENT_LIMIT)
            ->get();
    }

    /**
     * The planned maintenance due first, so one that is overdue comes before
     * an open window.
     */
    public function nextMaintenance(Project $project): ?Maintenance
    {
        return $this->linkedTo(Maintenance::query(), $project)->planned()->orderByDue()->first();
    }

    /**
     * What went wrong in one bar: per check that failed, how often, and its
     * errors (the raw results are kept for a while; older bars only have
     * their counts).
     *
     * @return list<array{lane: TimelineLane, up: int, down: int, errors: list<array{error: string, count: int}>}>
     */
    public function failures(Timeline $timeline, int $index): array
    {
        $bucket = $timeline->buckets[$index] ?? null;

        if ($bucket === null) {
            return [];
        }

        $failing = array_values(array_filter($timeline->lanes, fn (TimelineLane $lane): bool => $lane->counts[$index]['down'] > 0));

        $errors = CheckResult::query()
            ->whereIn('check_id', array_map(fn (TimelineLane $lane): int => $lane->check->id, $failing))
            ->where('ok', false)
            ->where('checked_at', '>=', $bucket['start'])
            ->where('checked_at', '<', $bucket['end'])
            ->groupBy('check_id', 'error')
            ->selectRaw('check_id, error, count(*) as total')
            ->toBase()
            ->get()
            ->groupBy('check_id');

        return array_map(fn (TimelineLane $lane): array => [
            'lane' => $lane,
            'up' => $lane->counts[$index]['up'],
            'down' => $lane->counts[$index]['down'],
            'errors' => ($errors[$lane->check->id] ?? collect())
                ->sortByDesc('total')
                ->map(fn (object $row): array => ['error' => (string) ($row->error ?? __('No error message')), 'count' => (int) $row->total])
                ->values()
                ->all(),
        ], $failing);
    }

    /**
     * When an incident began, as far as is known: an imported one only has its day.
     */
    public static function incidentStart(Incident $incident): CarbonImmutable
    {
        return $incident->started_at ?? $incident->occurred_on->shiftTimezone(config('herdbook.display_timezone'))->startOfDay()->utc();
    }

    /**
     * When it ended: resolved, or (still open) now. A resolved one without a
     * time is a moment.
     */
    public static function incidentEnd(Incident $incident): CarbonImmutable
    {
        return $incident->resolved_at ?? ($incident->status === IncidentStatus::Resolved ? self::incidentStart($incident) : Date::now());
    }

    /**
     * Clock hours up to a week (the running one last), calendar days in the
     * display timezone beyond: a day can be 23 or 25 hours long.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string, share: null, worst: null, ms: null}>
     */
    private function buckets(TimelineRange $range): array
    {
        $count = $range->buckets();

        if ($range->daily()) {
            $today = Date::now(config('herdbook.display_timezone'))->startOfDay();
            $bounds = array_map(fn (int $index): array => [
                $today->subDays($count - 1 - $index)->utc(),
                $today->subDays($count - 2 - $index)->utc(),
            ], range(0, $count - 1));
        } else {
            $first = CheckHistory::firstHour($count);
            $bounds = array_map(fn (int $index): array => [$first->addHours($index), $first->addHours($index + 1)], range(0, $count - 1));
        }

        return array_map(fn (array $bound): array => [
            'start' => $bound[0],
            'end' => $bound[1],
            'label' => self::label($range, $bound[0], $bound[1]),
            'share' => null,
            'worst' => null,
            'ms' => null,
        ], $bounds);
    }

    /**
     * "23 Oct 13:00–14:00", or "Wed 21 Oct" for a day (formats in the active
     * language). In the hour the clocks go back, the zone tells the two
     * 02:00s apart.
     */
    private static function label(TimelineRange $range, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $timezone = config('herdbook.display_timezone');
        $from = $start->timezone($timezone);
        $to = $end->timezone($timezone);

        if ($range->daily()) {
            return $from->isoFormat(__('formats.iso_weekday_day_month'));
        }

        return $from->utcOffset() === $to->utcOffset()
            ? $from->translatedFormat(__('formats.day_month_time')).'–'.$to->format('H:i')
            : $from->translatedFormat(__('formats.day_month_time_zone')).'–'.$to->format('H:i T');
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $buckets
     * @return array<int, int> hour index (from $start) => bar
     */
    private function bucketOfHour(array $buckets, CarbonImmutable $start, int $hours): array
    {
        $map = [];

        foreach ($buckets as $index => $bucket) {
            $from = (int) round($start->diffInHours($bucket['start']));
            $to = min($hours, (int) round($start->diffInHours($bucket['end'])));

            for ($hour = $from; $hour < $to; $hour++) {
                $map[$hour] = $index;
            }
        }

        return $map;
    }

    /**
     * A check's results per bar, and per bar its worst hour (the tone of a
     * day: an outage of twenty minutes is a bad hour, not 98% of a day).
     *
     * @param  list<array{up: int, down: int}>  $history
     * @param  array<int, int>  $bucketOfHour
     */
    private function lane(Check $check, array $history, array $bucketOfHour, int $buckets): TimelineLane
    {
        $counts = array_fill(0, $buckets, ['up' => 0, 'down' => 0]);
        $worst = array_fill(0, $buckets, null);

        foreach ($history as $hour => $entry) {
            $index = $bucketOfHour[$hour] ?? null;
            $total = $entry['up'] + $entry['down'];

            if ($index === null || $total === 0) {
                continue;
            }

            $counts[$index]['up'] += $entry['up'];
            $counts[$index]['down'] += $entry['down'];
            $worst[$index] = min($worst[$index] ?? 1.0, (float) ($entry['up'] / $total));
        }

        return new TimelineLane($check, $counts, $worst);
    }

    /**
     * A check's average response time per bar, weighted by the number of
     * results in each hour, as checks:aggregate does for days.
     *
     * @param  list<array{up: int, down: int, ms: ?int}>  $history
     * @param  array<int, int>  $bucketOfHour
     * @return array<int, float> bar => milliseconds
     */
    private function timesPerBucket(array $history, array $bucketOfHour): array
    {
        $sums = [];

        foreach ($history as $hour => $entry) {
            $index = $bucketOfHour[$hour] ?? null;

            if ($index !== null && $entry['ms'] !== null) {
                $weight = $entry['up'] + $entry['down'];
                $sums[$index] = [($sums[$index][0] ?? 0) + $entry['ms'] * $weight, ($sums[$index][1] ?? 0) + $weight];
            }
        }

        return array_map(fn (array $sum): float => $sum[0] / $sum[1], array_filter($sums, fn (array $sum): bool => $sum[1] > 0));
    }

    /**
     * @return Collection<int, Maintenance>
     */
    private function maintenance(Project $project, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->linkedTo(Maintenance::query(), $project)
            ->with(['servers:id,name', 'projects:id,name'])
            ->where('status', '!=', MaintenanceStatus::Cancelled)
            ->where('starts_at', '<', $end)
            ->whereRaw('coalesce(ends_at, starts_at) >= ?', [$start])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Those that overlap the range: from their start to their end.
     *
     * @return Collection<int, Incident>
     */
    private function incidents(Project $project, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->linkedTo(Incident::query(), $project)
            ->where(fn (Builder $query) => $query
                ->where('status', '!=', IncidentStatus::Resolved)
                ->orWhere('resolved_at', '>=', $start)
                ->orWhere('occurred_on', '>=', $start->timezone(config('herdbook.display_timezone'))->toDateString()))
            ->get()
            ->filter(fn (Incident $incident): bool => self::incidentStart($incident) < $end && self::incidentEnd($incident) >= $start)
            ->sortBy(fn (Incident $incident): int => self::incidentStart($incident)->getTimestamp())
            ->values();
    }

    /**
     * Linked to the project, to a server its production needs, or to the
     * network such a server stands in (ProjectIncidents).
     *
     * @template TModel of Maintenance|Incident
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function linkedTo(Builder $query, Project $project): Builder
    {
        $servers = $this->health->productionServerIds($project);
        $networks = $this->health->productionNetworkIds($project);

        return $query->where(fn (Builder $query) => $query
            ->whereHas('projects', fn (Builder $query) => $query->whereKey($project->id))
            ->when($servers !== [], fn (Builder $query) => $query->orWhereHas('servers', fn (Builder $query) => $query->whereKey($servers)))
            // Maintenance is not linked to networks, only incidents.
            ->when($networks !== [] && $query->getModel() instanceof Incident, fn (Builder $query) => $query->orWhereHas('networks', fn (Builder $query) => $query->whereKey($networks))));
    }
}
