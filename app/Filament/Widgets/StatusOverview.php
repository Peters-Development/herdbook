<?php

namespace App\Filament\Widgets;

use App\Enums\CheckState;
use App\Enums\HealthState;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\LocalTime;
use App\Filament\Support\RenewalSchema;
use App\Models\Check;
use App\Models\Contracts\Renewable;
use App\Models\Incident;
use App\Models\Project;
use App\Services\BackupFreshness;
use App\Services\Checks\MonitoringPulse;
use App\Services\Health\ProjectHealth;
use App\Services\RenewalAttention;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

/**
 * The band at the top of the dashboard: one number per thing that can need
 * you, each linking to the list behind it.
 */
class StatusOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    // Rendered with the page, not in a request of its own: the band and the
    // tiles then share one ProjectHealth.
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    protected int|array|null $columns = ['@lg' => 3, '!@lg' => 3, '@3xl' => 5, '!@xl' => 5];

    protected function getStats(): array
    {
        return [
            $this->projects(),
            $this->checks(),
            $this->incidents(),
            $this->renewals(),
            $this->backup(),
        ];
    }

    private function projects(): Stat
    {
        $health = app(ProjectHealth::class);
        $all = Project::query()
            ->where('status', '!=', ProjectStatus::Archived)
            ->pluck('id')
            ->map(fn (int $id): HealthState => $health->forProject($id, history: false)->state);

        // A project that needs no checks is not a gap: "1 of 1 healthy", not "1 of 2"
        // (and it has no tile on the board below).
        $states = $all->reject(fn (HealthState $state): bool => $state === HealthState::Unmonitored);

        $down = $states->filter(fn (HealthState $state): bool => $state === HealthState::Down)->count();
        $degraded = $states->filter(fn (HealthState $state): bool => $state === HealthState::Degraded)->count();
        $up = $states->filter(fn (HealthState $state): bool => $state === HealthState::Up)->count();

        return Stat::make(__('Projects'), match (true) {
            $down > 0 => trans_choice(':count outage|:count outages', $down),
            $degraded > 0 => trans_choice(':count needs attention|:count need attention', $degraded),
            // Nothing up: say what most of them are (paused, not measured yet,
            // unwatched; unmonitored only when that is all there is).
            $up === 0 => ($states->isEmpty() ? $all : $states)->countBy(fn (HealthState $state): string => $state->getLabel())->sortDesc()->keys()->first() ?? __('No projects'),
            default => __('Healthy'),
        })
            ->description($states->isEmpty() && $all->isNotEmpty() ? null : __(':up of :total healthy', ['up' => $up, 'total' => $states->count()]))
            ->color(match (true) {
                $down > 0 => 'danger',
                $degraded > 0 => 'warning',
                $up === 0 => 'gray',
                default => 'success',
            })
            ->icon('heroicon-o-rectangle-stack')
            ->url(ProjectResource::getUrl());
    }

    private function checks(): Stat
    {
        $pulse = app(MonitoringPulse::class);
        $active = Check::query()->where('enabled', true)->get(['id', 'type', 'enabled', 'state', 'interval_seconds', 'last_checked_at', 'created_at']);

        // The list behind the number: what is down, when something is.
        $stat = Stat::make(__('Checks up'), $active->where('state', CheckState::Up)->count().'/'.$active->count())
            ->icon('heroicon-o-signal')
            ->url($active->contains('state', CheckState::Down)
                ? CheckResource::getUrl('index', ['filters' => ['state' => ['value' => CheckState::Down->value]]])
                : CheckResource::getUrl());

        if ($active->isEmpty()) {
            return $stat->description(__('No active checks'))->color('gray');
        }

        if ($pulse->isStalled()) {
            $last = $pulse->lastResultAt();

            return $stat
                ->description($last ? __('No check results since :time', ['time' => LocalTime::format($last)]) : __('No check results yet'))
                ->color('danger');
        }

        // A certificate close to expiry or an odd DNS answer is down too, but
        // the site still answers: a warning, as on the tiles.
        $down = $active->where('state', CheckState::Down);
        $unavailable = $down->filter(fn (Check $check): bool => $check->type->measuresAvailability())->count();
        $warnings = $down->count() - $unavailable;
        $doubt = $active->where('state', CheckState::Pending)->count();
        $stale = $active->filter->isStale()->count();

        return $stat
            ->description(implode(' · ', array_filter([
                $unavailable > 0 ? __(':count down', ['count' => $unavailable]) : null,
                $warnings > 0 ? trans_choice(':count certificate or DNS warning|:count certificate or DNS warnings', $warnings) : null,
                $doubt > 0 ? __(':count possibly down', ['count' => $doubt]) : null,
                $stale > 0 ? __(':count without recent data', ['count' => $stale]) : null,
            ])) ?: __('All up'))
            ->color(match (true) {
                $unavailable > 0 => 'danger',
                $warnings > 0 || $doubt > 0 || $stale > 0 => 'warning',
                default => 'success',
            });
    }

    private function incidents(): Stat
    {
        $unresolved = Incident::query()->where('status', '!=', IncidentStatus::Resolved)->orderBy('severity')->orderByDesc('occurred_on')->get(['id', 'title', 'severity', 'status']);
        $open = $unresolved->where('status', IncidentStatus::Open)->values();
        $monitoring = $unresolved->count() - $open->count();
        $worst = $open->first();

        return Stat::make(__('Open incidents'), $open->count())
            ->description(implode(' · ', array_filter([
                $worst ? Str::limit("{$worst->severity->getLabel()}: {$worst->title}", 60) : __('Nothing open'),
                $monitoring > 0 ? __(':count being watched', ['count' => $monitoring]) : null,
            ])))
            ->color(match (true) {
                $worst === null => $monitoring > 0 ? 'warning' : 'success',
                $worst->severity === IncidentSeverity::Sev1 => 'danger',
                default => 'warning',
            })
            ->icon('heroicon-o-fire')
            ->url($open->count() === 1
                ? IncidentResource::getUrl('view', ['record' => $worst])
                : IncidentResource::getUrl('index', ['filters' => ['status' => ['value' => IncidentStatus::Open->value]]]));
    }

    private function renewals(): Stat
    {
        $due = app(RenewalAttention::class)->all();
        $first = $due->first();

        return Stat::make(__('Renewals'), $due->count())
            ->description($first
                ? __('Next: :name, :date', ['name' => $first->name, 'date' => $first->expires_on->translatedFormat(__('formats.date'))])
                : __('Nothing to do'))
            ->color(match (true) {
                $due->contains(fn (Renewable $item): bool => $item->renewalStatus()->getColor() === 'danger') => 'danger',
                $due->isNotEmpty() => 'warning',
                default => 'success',
            })
            ->icon('heroicon-o-calendar-days')
            // The list behind the number: what needs attention, of the kind due first.
            ->url(($first ? RenewalSchema::resource($first) : DomainResource::class)::getUrl('index', ['filters' => ['needs_attention' => ['isActive' => true]]]));
    }

    private function backup(): Stat
    {
        $backups = app(BackupFreshness::class);
        $last = $backups->lastSuccess();

        if ($last === null) {
            return Stat::make(__('Backup'), '—')
                ->description(__('Not set up yet: no successful backup reported'))
                ->color('gray')
                ->icon('heroicon-o-archive-box');
        }

        return Stat::make(__('Backup'), ucfirst(LocalTime::format($last, withDay: true)))
            ->description($backups->isStale() ? __('Too old: backups have stopped running') : __('Last successful backup'))
            ->color($backups->isStale() ? 'danger' : 'success')
            ->icon('heroicon-o-archive-box');
    }
}
