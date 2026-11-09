<?php

namespace App\Mcp;

use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Maintenance;
use App\Models\Project;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What the MCP tools show an agent, field by field: a new column never
 * reaches an agent by itself. No notes, IP addresses, provider hostnames
 * (they often hold the address), repository URLs (they may hold credentials),
 * SSH details, costs, credential references or contact details. Enum values rather than
 * translated labels, and times in ISO 8601 with their offset, in the
 * display timezone.
 */
final class Serializer
{
    /**
     * A plan or report is cut here, so that both fit in one answer an agent
     * reads whole (the package sends structured content twice: as data and
     * as text).
     */
    public const int MAX_TEXT = 10000;

    /**
     * What maintenance() reads, to eager-load.
     */
    public const array MAINTENANCE_RELATIONS = ['servers', 'projects', 'user'];

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
            'title' => $maintenance->title,
            'tasks' => array_values($maintenance->tasks ?? []),
            'status' => $maintenance->status->value,
            'overdue' => $maintenance->isOverdue(),
            'starts_at' => self::time($maintenance->starts_at),
            'ends_at' => self::time($maintenance->ends_at),
            'servers' => self::references($maintenance->servers),
            'projects' => self::references($maintenance->projects),
            'created_by' => $maintenance->user?->name,
            'url' => MaintenanceResource::getUrl('view', ['record' => $maintenance]),
        ];
    }

    /**
     * maintenance() with its plan and report, each cut at MAX_TEXT; "truncated"
     * names the ones that were longer.
     *
     * @return array<string, mixed>
     */
    public static function maintenanceWithTexts(Maintenance $maintenance): array
    {
        $texts = ['plan' => $maintenance->plan, 'report' => $maintenance->report];

        return [
            ...self::maintenance($maintenance),
            ...array_map(fn (?string $text): ?string => $text === null ? null : mb_substr($text, 0, self::MAX_TEXT), $texts),
            'truncated' => array_keys(array_filter($texts, fn (?string $text): bool => mb_strlen((string) $text) > self::MAX_TEXT)),
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
    private static function reference(Project|Server $model): array
    {
        return ['id' => $model->id, 'name' => $model->name];
    }

    /**
     * @param  Collection<int, covariant Project|Server>  $models
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
