<?php

namespace App\Services\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Draft incidents from long outages: a critical production check that is
 * down for a while opens one (or joins a recent open one of its project),
 * with the start taken from the first failed result, so the detection time
 * and the time to detect are right. A recovery is added to its timeline; the
 * incident moves to "monitoring" once none of its checks is down any more.
 */
final class CheckIncidents
{
    /**
     * An open check incident older than this is not joined: an incident
     * nobody closed must not swallow next week's outage.
     */
    private const int JOIN_WITHIN_HOURS = 12;

    public function openForLongOutages(CarbonImmutable $now): int
    {
        $minutes = (int) config('herdbook.checks.incident_after_minutes');
        $opened = 0;

        // Each down event is decided on once (incident_evaluated_at): an
        // incident deleted by hand does not come back, and events that need
        // none are not looked at again every minute.
        $events = CheckEvent::query()
            ->with(['check.hostname.environment.project', 'check.server'])
            ->where('to_state', CheckState::Down)
            ->whereNull('incident_evaluated_at')
            ->where('occurred_at', '<=', $now->subMinutes($minutes))
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            DB::transaction(function () use ($event, &$opened): void {
                // Under the same lock RunChecks records with: a check that just
                // came back up gets no incident, and its recovery is not missed.
                $check = Check::query()->lockForUpdate()->with(['hostname.environment.project', 'server'])->find($event->check_id);

                if (CheckEvent::query()->whereKey($event->id)->value('incident_evaluated_at') !== null) {
                    return;
                }

                $event->update(['incident_evaluated_at' => now()]);

                $current = ! CheckEvent::query()->where('check_id', $event->check_id)->where('id', '>', $event->id)->exists();

                if (! $current
                    || $check?->state !== CheckState::Down
                    || ! $check->enabled
                    || $check->alert_level !== AlertLevel::Critical
                    || ! $check->watchesProduction()) {
                    return;
                }

                $event->setRelation('check', $check);
                $existing = $this->openIncidentFor($check);
                // Locked before its timeline gets a row: two checks of one
                // incident handled at once must not deadlock on it.
                $incident = $existing ? Incident::query()->lockForUpdate()->find($existing->id) : $this->createIncident($event);
                $opened += $incident->wasRecentlyCreated ? 1 : 0;

                $event->update(['incident_id' => $incident->id]);

                if (! $incident->wasRecentlyCreated) {
                    $incident->timelineEntries()->create([
                        'occurred_at' => $event->occurred_at,
                        'note' => $event->error
                            ? __('Also down: :check (:error)', ['check' => $event->check->name, 'error' => $event->error])
                            : __('Also down: :check', ['check' => $event->check->name]),
                    ]);

                    // What this check reaches belongs to the incident too: a storage
                    // server joining an app's incident also takes the other projects
                    // that mount it.
                    $incident->projects()->syncWithoutDetaching($check->affectedProjects()->pluck('id')->all());
                    $incident->servers()->syncWithoutDetaching($check->affectedServers()->pluck('id')->all());

                    if ($incident->status === IncidentStatus::Monitoring) {
                        $incident->update(['status' => IncidentStatus::Open]);
                    }
                }
            });
        }

        return $opened;
    }

    public function recovered(CheckEvent $upEvent): void
    {
        $downEvent = CheckEvent::query()
            ->where('check_id', $upEvent->check_id)
            ->where('to_state', CheckState::Down)
            ->where('id', '<', $upEvent->id)
            ->latest('id')
            ->first();

        if ($downEvent?->incident_id === null) {
            return;
        }

        DB::transaction(function () use ($downEvent, $upEvent): void {
            // The row first, then its timeline: the status is read and set
            // under the same lock (see openForLongOutages).
            $incident = Incident::query()->lockForUpdate()->find($downEvent->incident_id);

            if ($incident === null) {
                return;
            }

            $incident->timelineEntries()->create([
                'occurred_at' => $upEvent->occurred_at,
                'note' => __('Up again: :check', ['check' => $upEvent->check->name]),
            ]);

            $stillDown = Check::query()
                ->where('enabled', true)
                ->where('state', CheckState::Down)
                ->whereIn('id', CheckEvent::query()->where('incident_id', $incident->id)->select('check_id'))
                ->exists();

            if ($incident->status === IncidentStatus::Open && ! $stillDown) {
                $incident->update(['status' => IncidentStatus::Monitoring]);
            }
        });
    }

    /**
     * The open incident from checks of the same project, if there is one.
     */
    private function openIncidentFor(Check $check): ?Incident
    {
        $projectIds = $check->affectedProjects()->pluck('id')->all();

        if ($projectIds === []) {
            return null;
        }

        return Incident::query()
            ->where('source', IncidentSource::Check)
            ->where('status', '!=', IncidentStatus::Resolved)
            ->where('detected_at', '>=', now()->subHours(self::JOIN_WITHIN_HOURS))
            ->whereHas('projects', fn ($query) => $query->whereKey($projectIds))
            ->latest('id')
            ->first();
    }

    private function createIncident(CheckEvent $event): Incident
    {
        $check = $event->check;
        $projects = $check->affectedProjects();
        $startedAt = $this->firstFailureOfStreak($check, $event) ?? $event->occurred_at;

        $project = $projects->first()?->name;

        $incident = Incident::create([
            'title' => Str::limit($project
                ? __(':project: :check unreachable', ['project' => $project, 'check' => $check->name])
                : __(':check unreachable', ['check' => $check->name]), 250),
            'severity' => IncidentSeverity::Sev1,
            'status' => IncidentStatus::Open,
            'occurred_on' => $startedAt->timezone(config('herdbook.display_timezone'))->toDateString(),
            'started_at' => $startedAt,
            'detected_at' => $event->occurred_at,
            'impact' => $event->error,
            'source' => IncidentSource::Check,
        ]);

        $incident->projects()->attach($projects->pluck('id')->all());
        $incident->servers()->attach($check->affectedServers()->pluck('id')->all());
        $incident->timelineEntries()->create([
            'occurred_at' => $event->occurred_at,
            'note' => $event->error
                ? __('Check :check went down: :error', ['check' => $check->name, 'error' => $event->error])
                : __('Check :check went down.', ['check' => $check->name]),
        ]);

        return $incident;
    }

    /**
     * The first failed result after the last good one before $event.
     */
    private function firstFailureOfStreak(Check $check, CheckEvent $event): ?CarbonImmutable
    {
        $lastGood = CheckResult::query()
            ->where('check_id', $check->id)
            ->where('ok', true)
            ->where('checked_at', '<=', $event->occurred_at)
            ->max('checked_at');

        return CheckResult::query()
            ->where('check_id', $check->id)
            ->where('ok', false)
            ->when($lastGood, fn ($query) => $query->where('checked_at', '>', $lastGood))
            ->where('checked_at', '<=', $event->occurred_at)
            ->orderBy('checked_at')
            ->first()
            ?->checked_at;
    }
}
