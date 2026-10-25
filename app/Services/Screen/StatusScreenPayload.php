<?php

namespace App\Services\Screen;

use App\Enums\HealthState;
use App\Enums\ProjectStatus;
use App\Models\Incident;
use App\Models\Project;
use App\Services\BackupFreshness;
use App\Services\Checks\MonitoringPulse;
use App\Services\Health\HealthReport;
use App\Services\Health\ProjectHealth;
use App\Services\Health\ProjectIncidents;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What the status screen shows, field by field: names and states, never a
 * model. No hostnames, addresses or check names (an incident made by a
 * check has its hostname in the title, so an incident is shown by its
 * severity only). Visitors can read along. The labels are in the active
 * language.
 */
final class StatusScreenPayload
{
    /**
     * Every screen asks every 20 seconds; they share one computation.
     */
    private const int CACHE_SECONDS = 15;

    private const string CACHE_KEY = 'status-screen:payload';

    public function __construct(
        private readonly ProjectHealth $health,
        private readonly ProjectIncidents $incidents,
        private readonly MonitoringPulse $pulse,
        private readonly BackupFreshness $backups,
    ) {}

    /**
     * The page's own version: a screen that sees another one reloads. Its
     * text is part of it: another default language, or new translations.
     */
    public static function version(): string
    {
        $translations = lang_path(app()->getLocale().'.json');

        return hash('xxh128', implode('|', [
            hash_file('xxh128', resource_path('views/screen.blade.php')),
            app()->getLocale(),
            is_file($translations) ? hash_file('xxh128', $translations) : '',
        ]));
    }

    /**
     * Per language: the payload carries translated labels.
     *
     * @return array<string, mixed>
     */
    public function cached(): array
    {
        return Cache::remember(self::CACHE_KEY.':'.app()->getLocale(), self::CACHE_SECONDS, fn (): array => $this->build());
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $projects = Project::query()
            ->where('on_status_screen', true)
            ->where('status', '!=', ProjectStatus::Archived)
            ->orderBy('name')
            ->get(['id', 'name']);

        $reports = $projects->mapWithKeys(fn (Project $project): array => [$project->id => $this->health->forProject($project)]);
        $lastResult = $this->pulse->lastResultAt();
        $lastBackup = $this->backups->lastSuccess();

        return [
            'generated_at' => $this->time(now()),
            'timezone' => config('herdbook.display_timezone'),
            'monitoring' => [
                'stalled' => $this->pulse->isStalled(),
                'last_result_at' => $this->time($lastResult),
            ],
            'backup' => [
                'last_success_at' => $this->time($lastBackup),
                'stale' => $this->backups->isStale(),
            ],
            'summary' => collect(HealthState::cases())
                ->mapWithKeys(fn (HealthState $state): array => [$state->value => $reports->filter(fn (HealthReport $report): bool => $report->state === $state)->count()])
                ->all(),
            'projects' => $projects->map(fn (Project $project): array => [
                // Names need not be unique: the screen keys its tiles on this.
                'key' => $project->id,
                'name' => Str::limit($project->name, 60),
                ...$this->report($reports[$project->id]),
                'incidents' => $this->openIncidents($project),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(HealthReport $report): array
    {
        return [
            'state' => $report->state->value,
            'label' => $report->state->getLabel(),
            'since' => $this->time($report->since),
            'uptime' => $report->uptime,
            'response_ms' => $report->responseMs,
            'hours' => $report->hourTones(),
        ];
    }

    /**
     * Open incidents (not those being monitored after recovery), with the
     * reach of ProjectIncidents: severity and since when, nothing else.
     *
     * @return list<array{severity: string, label: string, since: ?string}>
     */
    private function openIncidents(Project $project): array
    {
        return $this->incidents->open($project)
            ->map(fn (Incident $incident): array => [
                'severity' => $incident->severity->value,
                'label' => $incident->severity->getLabel(),
                'since' => $this->time($incident->started_at ?? $incident->detected_at ?? $incident->created_at),
            ])
            ->all();
    }

    private function time(?CarbonInterface $moment): ?string
    {
        return $moment?->utc()->toIso8601ZuluString();
    }
}
