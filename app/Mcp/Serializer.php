<?php

namespace App\Mcp;

use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentTimelineEntry;
use App\Models\Maintenance;
use App\Models\Project;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * What the MCP tools show an agent, field by field: a new column never
 * reaches an agent by itself. No notes (of inventory), IP addresses, provider hostnames
 * (they often hold the address), repository URLs (they may hold credentials),
 * SSH details, costs, credential references or contact details. Enum values rather than
 * translated labels, and times in ISO 8601 with their offset, in the
 * display timezone.
 */
final class Serializer
{
    /**
     * The length of a plan or report: what an agent may write, and where one
     * written in the panel is cut when an agent reads it, so that both fit
     * in one answer it reads whole (the package sends structured content
     * twice: as data and as text).
     */
    public const int MAX_TEXT = 10000;

    /**
     * What maintenance() reads, to eager-load.
     */
    public const array MAINTENANCE_RELATIONS = ['servers', 'projects', 'user'];

    /**
     * What incident() reads, to eager-load: who wrote it down, its links,
     * and its action items (counted).
     */
    public const array INCIDENT_RELATIONS = ['user', 'projects', 'servers', 'domains', 'networks', 'clients', 'actions'];

    /**
     * What incidentWithTexts() reads besides: who wrote down and completed
     * each action item it lists.
     */
    public const array INCIDENT_WITH_TEXTS_RELATIONS = [...self::INCIDENT_RELATIONS, 'actions.user', 'actions.completedBy'];

    /**
     * How much of a timeline incidentWithTexts() shows: its latest entries,
     * at most TIMELINE_ENTRIES of them and TIMELINE_CHARACTERS of notes, so
     * the answer stays one an agent reads whole; a note is cut at MAX_NOTE
     * (monitoring writes one line, people a few).
     */
    public const int TIMELINE_ENTRIES = 100;

    public const int TIMELINE_CHARACTERS = 12000;

    public const int MAX_NOTE = 1000;

    /**
     * What an entry of the timeline costs besides its note: its moment,
     * names and keys.
     */
    public const int ENTRY_CHARACTERS = 100;

    /**
     * How many action items incidentWithTexts() shows: open ones first.
     */
    public const int ACTIONS = 30;

    /**
     * What project() reads, to eager-load.
     */
    public const array PROJECT_RELATIONS = ['client', 'environments.servers'];

    /**
     * What server() reads, to eager-load, with withLastDoneOfEachTask().
     */
    public const array SERVER_RELATIONS = ['provider', 'environments.project'];

    /**
     * What hostname() reads, to eager-load.
     */
    public const array HOSTNAME_RELATIONS = ['environment.project', 'environment.servers'];

    public static function time(?CarbonInterface $moment): ?string
    {
        return $moment?->timezone(config('herdbook.display_timezone'))->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    public static function maintenance(Maintenance $maintenance): array
    {
        return [
            'id' => $maintenance->id,
            // Masked (HiddenValues), as everything people write.
            'title' => app(HiddenValues::class)->mask($maintenance->title),
            'tasks' => array_values($maintenance->tasks ?? []),
            'status' => $maintenance->status->value,
            'overdue' => $maintenance->isOverdue(),
            'starts_at' => self::time($maintenance->starts_at),
            'ends_at' => self::time($maintenance->ends_at),
            'servers' => self::references($maintenance->servers),
            'projects' => self::references($maintenance->projects),
            'created_by' => $maintenance->user?->name,
            // The agent it was recorded through, if any.
            'via' => $maintenance->recorded_via,
            // Given back to the tools that change it: refused when it changed since.
            'version' => $maintenance->version(),
            'url' => MaintenanceResource::getUrl('view', ['record' => $maintenance]),
        ];
    }

    /**
     * maintenance() with its plan and report, masked (HiddenValues) and each
     * cut at MAX_TEXT; "truncated" names the ones that were longer, and
     * "texts_changed" who last changed each (null: no change in the
     * activity log).
     *
     * @return array<string, mixed>
     */
    public static function maintenanceWithTexts(Maintenance $maintenance): array
    {
        $texts = array_map(app(HiddenValues::class)->mask(...), ['plan' => $maintenance->plan, 'report' => $maintenance->report]);

        return [
            ...self::maintenance($maintenance),
            ...array_map(fn (?string $text): ?string => $text === null ? null : mb_substr($text, 0, self::MAX_TEXT), $texts),
            'truncated' => self::truncated($texts),
            'texts_changed' => array_map(self::textChange(...), $maintenance->lastTextChanges()),
        ];
    }

    /**
     * A timeline entry, its note masked and cut at MAX_NOTE. Eager-load: user.
     *
     * @param  string|null  $note  the note masked already
     * @return array<string, mixed>
     */
    public static function timelineEntry(IncidentTimelineEntry $entry, ?string $note = null): array
    {
        $note ??= (string) app(HiddenValues::class)->mask($entry->note);

        return [
            'occurred_at' => self::time($entry->occurred_at),
            'note' => mb_substr($note, 0, self::MAX_NOTE),
            'truncated' => mb_strlen($note) > self::MAX_NOTE,
            'created_by' => $entry->user?->name,
            'via' => $entry->recorded_via,
        ];
    }

    /**
     * An action item, who wrote it down and who completed it (with the
     * agents they came through); its id is what complete_incident_action
     * takes. Eager-load: user, completedBy.
     *
     * @return array<string, mixed>
     */
    public static function incidentAction(IncidentAction $action): array
    {
        return [
            'id' => $action->id,
            'description' => app(HiddenValues::class)->mask($action->description),
            'due_on' => $action->due_on?->toDateString(),
            'done_at' => self::time($action->done_at),
            'overdue' => $action->isOverdue(),
            'created_by' => $action->user?->name,
            'via' => $action->recorded_via,
            'completed_by' => $action->completedBy?->name,
            'completed_via' => $action->completed_via,
        ];
    }

    /**
     * The texts an agent didn't read whole: longer than MAX_TEXT once
     * masked, or not masked at all (hidden as a whole).
     *
     * @param  array<string, ?string>  $texts  masked
     * @return list<string>
     */
    private static function truncated(array $texts): array
    {
        $hidden = app(HiddenValues::class)->hiddenText();

        return array_keys(array_filter($texts, fn (?string $text): bool => mb_strlen((string) $text) > self::MAX_TEXT || $text === $hidden));
    }

    /**
     * Who changed a long text last, through which agent, and when.
     *
     * @return array{by: ?string, via: ?string, at: ?string}|null
     */
    private static function textChange(?Activity $change): ?array
    {
        return $change === null ? null : [
            'by' => $change->causer?->name,
            'via' => $change->properties?->get('via'),
            'at' => self::time($change->created_at),
        ];
    }

    /**
     * An incident without its texts and timeline. Its date is a calendar
     * day; its times carry their offset.
     *
     * @return array<string, mixed>
     */
    public static function incident(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            // Masked (HiddenValues): monitoring names a check after its target.
            'title' => app(HiddenValues::class)->mask($incident->title),
            'severity' => $incident->severity->value,
            'status' => $incident->status->value,
            // manual, import, or check: a draft monitoring opened.
            'source' => $incident->source->value,
            'occurred_on' => $incident->occurred_on?->toDateString(),
            'started_at' => self::time($incident->started_at),
            'detected_at' => self::time($incident->detected_at),
            'resolved_at' => self::time($incident->resolved_at),
            'projects' => self::references($incident->projects),
            'servers' => self::references($incident->servers),
            'domains' => self::references($incident->domains),
            'networks' => self::references($incident->networks),
            'clients' => self::references($incident->clients),
            'open_actions' => $incident->actions->whereNull('done_at')->count(),
            'created_by' => $incident->user?->name,
            'via' => $incident->recorded_via,
            'version' => $incident->version(),
            'url' => IncidentResource::getUrl('view', ['record' => $incident]),
        ];
    }

    /**
     * incident() with its impact, root cause and report, masked
     * (HiddenValues) and each cut at MAX_TEXT ("truncated" names the ones
     * that were longer), who last changed the root cause and report
     * ("texts_changed"), as much of its timeline as fits, in order
     * ("more_timeline": there are earlier entries), and its action items.
     * Eager-load: INCIDENT_WITH_TEXTS_RELATIONS.
     *
     * @return array<string, mixed>
     */
    public static function incidentWithTexts(Incident $incident): array
    {
        $hidden = app(HiddenValues::class);
        $texts = array_map($hidden->mask(...), ['impact' => $incident->impact, 'root_cause' => $incident->root_cause, 'report' => $incident->body]);
        $changes = $incident->lastTextChanges();

        $latest = $incident->latestTimelineEntries(self::TIMELINE_ENTRIES + 1);
        $shown = collect();
        $characters = 0;

        foreach ($latest->take(self::TIMELINE_ENTRIES) as $entry) {
            $note = (string) $hidden->mask($entry->note);
            $characters += min(mb_strlen($note), self::MAX_NOTE) + self::ENTRY_CHARACTERS;

            if ($characters > self::TIMELINE_CHARACTERS && $shown->isNotEmpty()) {
                break;
            }

            $shown->push(self::timelineEntry($entry, $note));
        }

        return [
            ...self::incident($incident),
            ...array_map(fn (?string $text): ?string => $text === null ? null : mb_substr($text, 0, self::MAX_TEXT), $texts),
            'truncated' => self::truncated($texts),
            'texts_changed' => ['root_cause' => self::textChange($changes['root_cause']), 'report' => self::textChange($changes['body'])],
            'timeline' => $shown->reverse()->values()->all(),
            'more_timeline' => $latest->count() > $shown->count(),
            'actions' => $incident->actions->sortBy([['done_at', 'asc'], ['id', 'asc']])->take(self::ACTIONS)->map(self::incidentAction(...))->values()->all(),
            'more_actions' => $incident->actions->count() > self::ACTIONS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function project(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'client' => $project->client?->name,
            'status' => $project->status->value,
            'environments' => $project->environments
                ->map(fn (Environment $environment): array => [...self::environment($environment), 'servers' => self::serversWithRoles($environment->servers)])
                ->all(),
            'url' => ProjectResource::getUrl('view', ['record' => $project]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function server(Server $server): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'status' => $server->status->value,
            'os' => $server->operating_system,
            'provider' => $server->provider?->name,
            // One entry per environment, with every role the server has there.
            'environments' => $server->environments->groupBy('id')->map(fn (Collection $rows): array => [
                'project' => self::reference($rows->first()->project),
                ...self::environment($rows->first()),
                'roles' => self::roles($rows),
            ])->sortBy(['project.name', 'name'])->values()->all(),
            'last_done' => array_map(self::time(...), $server->lastDone()),
            'url' => ServerResource::getUrl('view', ['record' => $server]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function hostname(Hostname $hostname): array
    {
        $environment = $hostname->environment;

        return [
            'id' => $hostname->id,
            'fqdn' => $hostname->fqdn,
            'project' => $environment === null ? null : self::reference($environment->project),
            'environment' => $environment === null ? null : self::environment($environment),
            'servers' => $environment === null ? [] : self::serversWithRoles($environment->servers),
            'url' => HostnameResource::getUrl('view', ['record' => $hostname]),
        ];
    }

    /**
     * @return array{name: string, kind: string}
     */
    private static function environment(Environment $environment): array
    {
        return ['name' => $environment->name, 'kind' => $environment->kind->value];
    }

    /**
     * @return array{id: int, name: string}
     */
    private static function reference(Model $model): array
    {
        return ['id' => $model->id, 'name' => $model->name];
    }

    /**
     * @param  Collection<int, covariant Model>  $models  with an id and a name
     * @return list<array{id: int, name: string}>
     */
    private static function references(Collection $models): array
    {
        return $models->map(self::reference(...))->values()->all();
    }

    /**
     * The servers of an environment by name, each once with every role it has
     * there (one row per role in the pivot).
     *
     * @param  Collection<int, Server>  $servers
     * @return list<array{id: int, name: string, roles: list<string>}>
     */
    private static function serversWithRoles(Collection $servers): array
    {
        return $servers->groupBy('id')
            ->map(fn (Collection $rows): array => [...self::reference($rows->first()), 'roles' => self::roles($rows)])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Environment|Server>  $rows  models of one pivot (environment_server)
     * @return list<string>
     */
    private static function roles(Collection $rows): array
    {
        return $rows->map(fn (Environment|Server $row): string => $row->pivot->role->value)->unique()->sort()->values()->all();
    }
}
