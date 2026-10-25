<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\MaintenanceStatus;
use App\Enums\TimelineRange;
use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\LocalTime;
use App\Filament\Support\MaintenanceSchema;
use App\Models\CheckEvent;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Project;
use App\Services\Health\ProjectHealth;
use App\Services\Health\ProjectIncidents;
use App\Services\Health\ProjectTimeline;
use App\Services\Health\Timeline;
use App\Services\Health\TimelineLane;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * How a project's production did over time, where the dashboard tile leads:
 * the bars over a range, per check, the response time, and the maintenance,
 * incidents and outages of that time. A bar opens what went wrong in it.
 *
 * @property-read Project $record
 */
class ViewProjectHealth extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected string $view = 'filament.resources.projects.pages.view-project-health';

    #[Url(as: 'period')]
    public string $range = TimelineRange::Day->value;

    /**
     * The room a band takes at least, in percent of the width: about its icon
     * (its minimum width in the CSS) on a narrow screen.
     */
    private const float BAND_RESERVE = 5.0;

    /**
     * The start (Unix time) of the open bar: a moment, not a position, so it
     * stays the same bar when the minute poll moves the hours along.
     */
    public ?int $selected = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->record), 403);
    }

    public static function getNavigationLabel(): string
    {
        return __('Status');
    }

    public function getTitle(): string
    {
        return __('Status of :name', ['name' => $this->record->name]);
    }

    public function getBreadcrumb(): string
    {
        return __('Status');
    }

    public function updatedRange(): void
    {
        $this->selected = null;
    }

    public function select(?int $start): void
    {
        $this->selected = $start === $this->selected ? null : $start;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $range = TimelineRange::tryFrom($this->range) ?? TimelineRange::Day;
        $timelines = app(ProjectTimeline::class);
        $timeline = $timelines->build($this->record, $range);
        $bucket = $this->selected === null ? null : $timeline->bucketAt(CarbonImmutable::createFromTimestamp($this->selected));

        // The open bar has left the range (the hours moved on): close it.
        if ($bucket === null) {
            $this->selected = null;
        }

        return [
            'ranges' => TimelineRange::cases(),
            'current' => $range,
            'timeline' => $timeline,
            'health' => app(ProjectHealth::class)->forProject($this->record, history: false),
            'openIncidents' => app(ProjectIncidents::class)->open($this->record)->count(),
            'next' => $timelines->nextMaintenance($this->record),
            'bucket' => $bucket,
            // The one bar in the tab order; the arrow keys move along the others.
            'focus' => $bucket ?? array_key_last($timeline->buckets),
            'maintenanceBands' => $this->stack($timeline->maintenance->map(fn (Maintenance $maintenance): array => $this->maintenanceBand($timeline, $maintenance))->all()),
            'incidentBands' => $this->stack($timeline->incidents->map(fn (Incident $incident): array => $this->incidentBand($timeline, $incident))->all()),
            'failures' => $bucket === null ? [] : $timelines->failures($timeline, $bucket),
            'feed' => $this->feed($timelines, $timeline, $bucket),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function maintenanceBand(Timeline $timeline, Maintenance $maintenance): array
    {
        $when = LocalTime::format($maintenance->starts_at, withDay: true).($maintenance->ends_at ? ' – '.LocalTime::format($maintenance->ends_at, withDay: true) : '');

        return [
            ...$timeline->band($maintenance->starts_at, $maintenance->ends_at),
            ...$timeline->span($maintenance->starts_at, $maintenance->ends_at),
            // Blue, not the status colour: "done" is green, and would vanish between green bars.
            'tone' => $maintenance->isOverdue() ? 'warning' : 'info',
            'planned' => $maintenance->status === MaintenanceStatus::Planned,
            'url' => MaintenanceResource::getUrl('view', ['record' => $maintenance]),
            'label' => $maintenance->title,
            'title' => implode(' · ', [__('Maintenance: :title', ['title' => $maintenance->title]), $when, MaintenanceSchema::statusLabel($maintenance)]),
            'icon' => 'heroicon-m-wrench-screwdriver',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentBand(Timeline $timeline, Incident $incident): array
    {
        $start = ProjectTimeline::incidentStart($incident);
        $end = ProjectTimeline::incidentEnd($incident);

        return [
            ...$timeline->band($start, $end),
            ...$timeline->span($start, $end),
            // Red whatever its status: an incident is never the good news.
            'tone' => 'danger',
            'planned' => false,
            'url' => IncidentResource::getUrl('view', ['record' => $incident]),
            'label' => $incident->title,
            'title' => implode(' · ', [__('Incident: :title', ['title' => $incident->title]), $incident->severity->getLabel(), $incident->status->getLabel()]),
            'icon' => 'heroicon-m-exclamation-triangle',
        ];
    }

    /**
     * Bands that would overlap go on another row, as the renewal calendar
     * stacks its markers. What counts is where a band is drawn: the shortest
     * still shows its icon, and one near the right edge grows to the left.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return array{rows: int, bands: list<array<string, mixed>>}
     */
    private function stack(array $bands): array
    {
        $drawn = [];

        foreach ($bands as $index => $band) {
            $width = max($band['width'], self::BAND_RESERVE);
            $from = $band['flip'] ? $band['left'] + $band['width'] - $width : $band['left'];
            $drawn[$index] = [$from, $from + $width];
        }

        uasort($drawn, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $ends = [];

        foreach ($drawn as $index => [$from, $to]) {
            $row = 0;

            while (isset($ends[$row]) && $ends[$row] > $from) {
                $row++;
            }

            $ends[$row] = $to;
            $bands[$index]['row'] = $row;
        }

        return ['rows' => max(1, count($ends)), 'bands' => $bands];
    }

    /**
     * What happened, newest first: changes between up and down, maintenance
     * and incidents; with a bar open, only what overlaps it (a period that
     * ended as the bar began does not). Not what is still to come.
     *
     * @return list<array{at: CarbonImmutable, end: ?CarbonImmutable, kind: 'down'|'up'|'maintenance'|'incident', title: string, detail: ?string, url: string}>
     */
    private function feed(ProjectTimeline $timelines, Timeline $timeline, ?int $bucket): array
    {
        [$from, $to] = $bucket === null
            ? [$timeline->start, $timeline->end]
            : [$timeline->buckets[$bucket]['start'], $timeline->buckets[$bucket]['end']];

        // A bar gets its own changes, not what is left of the range's limit.
        $events = $bucket === null
            ? $timeline->events
            : $timelines->events(array_map(fn (TimelineLane $lane): int => $lane->check->id, $timeline->lanes), $from, $to);

        $now = CarbonImmutable::now();
        $periods = fn (CarbonImmutable $at, ?CarbonImmutable $end): bool => $at < $to && $at <= $now
            && ($end === null || $end->equalTo($at) ? $at >= $from : $end > $from);

        return collect()
            ->merge($events->map(fn (CheckEvent $event): array => [
                'at' => $event->occurred_at,
                'end' => null,
                'kind' => $event->wentDown() ? 'down' : 'up',
                'title' => $event->wentDown()
                    ? __('Down: :check', ['check' => $event->check->nameWithServer()])
                    : __('Up again: :check', ['check' => $event->check->nameWithServer()]),
                'detail' => $event->error,
                'url' => CheckResource::getUrl('view', ['record' => $event->check_id]),
            ]))
            ->merge($timeline->maintenance->map(fn (Maintenance $maintenance): array => [
                'at' => $maintenance->starts_at,
                'end' => $maintenance->ends_at,
                'kind' => 'maintenance',
                'title' => __('Maintenance: :title', ['title' => $maintenance->title]),
                'detail' => implode(' · ', array_filter([MaintenanceSchema::statusLabel($maintenance), MaintenanceSchema::linkedTo($maintenance)])),
                'url' => MaintenanceResource::getUrl('view', ['record' => $maintenance]),
            ]))
            ->merge($timeline->incidents->map(fn (Incident $incident): array => [
                'at' => ProjectTimeline::incidentStart($incident),
                'end' => ProjectTimeline::incidentEnd($incident),
                'kind' => 'incident',
                'title' => __('Incident: :title', ['title' => $incident->title]),
                'detail' => "{$incident->severity->getLabel()} · {$incident->status->getLabel()}",
                'url' => IncidentResource::getUrl('view', ['record' => $incident]),
            ]))
            ->filter(fn (array $item): bool => $periods($item['at'], $item['end']))
            ->sortByDesc(fn (array $item): int => $item['at']->getTimestamp())
            ->values()
            ->all();
    }
}
