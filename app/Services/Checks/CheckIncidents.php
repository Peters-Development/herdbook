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
use App\Models\Concerns\Authorship;
use App\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Draft incidents from long outages: a critical production check that is
 * down for a while opens one (or joins a recent unresolved one of its
 * projects or servers, also one a person or an agent opened, raising it to
 * SEV1), with the start taken from the first failed result, so the
 * detection time and the time to detect are right. A recovery is added to
 * its timeline; a draft of its own moves to "monitoring" once none of its
 * checks is down any more (what a person or an agent wrote down may be
 * about more than the checks: they decide).
 */
final class CheckIncidents
{
    /**
     * An unresolved incident detected longer ago than this is not joined:
     * an incident nobody closed must not swallow next week's outage.
     */
    private const int JOIN_WITHIN_HOURS = 12;

    /**
     * Without an author: monitoring writes it, also when a person's "Check
     * now" set it off (Authorship).
     */
    public function openForLongOutages(CarbonImmutable $now): int
    {
        return Authorship::without(fn (): int => $this->openOrJoin($now));
    }

    public function recovered(CheckEvent $upEvent): void
    {
        Authorship::without(fn () => $this->recover($upEvent));
    }

    private function openOrJoin(CarbonImmutable $now): int
    {
        $minutes = (int) config('herdbook.checks.incident_after_minutes');
        $opened = 0;

        // Each down event is decided on once (incident_evaluated_at): an
        // incident deleted by hand does not come back, and events that need
        // none are not looked at again every minute.
        // Only their ids: each attempt reads the event and its check again.
        $events = CheckEvent::query()
            ->where('to_state', CheckState::Down)
            ->whereNull('incident_evaluated_at')
            ->where('occurred_at', '<=', $now->subMinutes($minutes))
            ->orderBy('id')
            ->get(['id', 'check_id']);

        foreach ($events as $listed) {
            $opened += DB::transaction(function () use ($listed): int {
                // Under the same lock RunChecks records with: a check that just
                // came back up gets no incident, and its recovery is not missed.
                $check = Check::query()->lockForUpdate()->with(['hostname.environment.project', 'server'])->find($listed->check_id);

                // Read again in every attempt: one that was rolled back (a
                // deadlock, tried again) leaves its changes on the models.
                $event = CheckEvent::query()->find($listed->id);

                if ($event === null || $event->incident_evaluated_at !== null) {
                    return 0;
                }

                $event->update(['incident_evaluated_at' => now()]);

                $current = ! CheckEvent::query()->where('check_id', $event->check_id)->where('id', '>', $event->id)->exists();

                if (! $current
                    || $check?->state !== CheckState::Down
                    || ! $check->enabled
                    || $check->alert_level !== AlertLevel::Critical
                    || ! $check->watchesProduction()) {
                    return 0;
                }

                $event->setRelation('check', $check);
                $found = $this->openIncidentFor($check);
                // Locked, its links with it (as the edit form and the agent
                // tools lock it), before its timeline gets a row: two checks
                // of one incident handled at once must not deadlock on it.
                // Read again under the lock: resolved meanwhile, it is not joined.
                $existing = $found ? Incident::locked($found->id) : null;
                $incident = $existing !== null && $existing->status !== IncidentStatus::Resolved ? $existing : $this->createIncident($event);

                $event->update(['incident_id' => $incident->id]);

                if ($incident->wasRecentlyCreated) {
                    return 1;
                }

                $incident->timelineEntries()->create([
                    'occurred_at' => $event->occurred_at,
                    'note' => $event->error
                        ? __('Also down: :check (:error)', ['check' => $check->name, 'error' => $event->error])
                        : __('Also down: :check', ['check' => $check->name]),
                ]);

                // What this check reaches belongs to the incident too: a storage
                // server joining an app's incident also takes the other projects
                // that mount it. Its links as locked() read them: a plain read
                // could miss one linked meanwhile, and add it twice.
                $incident->projects()->attach(array_diff($check->affectedProjects()->pluck('id')->all(), $incident->projects->modelKeys()));
                $incident->servers()->attach(array_diff($check->affectedServers()->pluck('id')->all(), $incident->servers->modelKeys()));

                if ($incident->status === IncidentStatus::Monitoring) {
                    $incident->update(['status' => IncidentStatus::Open]);
                }

                // A critical production outage is SEV1, also in what a person
                // or an agent wrote down as less: the overviews go by it.
                if ($incident->severity !== IncidentSeverity::Sev1) {
                    $incident->update(['severity' => IncidentSeverity::Sev1]);
                    $incident->timelineEntries()->create([
                        'occurred_at' => $event->occurred_at,
                        'note' => __('Raised to SEV1: a critical production check is down.'),
                    ]);
                }

                return 0;
            }, attempts: 3);
        }

        return $opened;
    }

    private function recover(CheckEvent $upEvent): void
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

            if ($incident->source === IncidentSource::Check && $incident->status === IncidentStatus::Open && ! $stillDown) {
                $incident->update(['status' => IncidentStatus::Monitoring]);
            }
        }, attempts: 3);
    }

    /**
     * The unresolved incident of the same projects or servers, detected in
     * the last hours (without a detection time: written down in them),
     * whoever opened it: a person or an agent that was there first wrote down
     * the same outage. Not an imported one: that is a report of the past.
     */
    private function openIncidentFor(Check $check): ?Incident
    {
        $projectIds = $check->affectedProjects()->pluck('id')->all();
        $serverIds = $check->affectedServers()->pluck('id')->all();
        $since = now()->subHours(self::JOIN_WITHIN_HOURS);

        if ($projectIds === [] && $serverIds === []) {
            return null;
        }

        return Incident::query()
            ->unresolved()
            ->where('source', '!=', IncidentSource::Import)
            ->where(fn (Builder $query) => $query
                ->where('detected_at', '>=', $since)
                ->orWhere(fn (Builder $query) => $query->whereNull('detected_at')->where('created_at', '>=', $since)))
            ->where(fn (Builder $query) => $query
                ->whereHas('projects', fn (Builder $projects) => $projects->whereKey($projectIds))
                ->orWhereHas('servers', fn (Builder $servers) => $servers->whereKey($serverIds)))
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
