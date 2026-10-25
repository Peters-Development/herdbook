<?php

namespace App\Services\Health;

use App\Enums\CheckState;
use App\Enums\EnvironmentKind;
use App\Enums\HealthState;
use App\Models\Check;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Services\Checks\CheckHistory;
use App\Services\Impact\ImpactAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How projects and environments are doing, from their checks: those of the
 * environment's hostnames, those of the servers it runs on, and those of
 * the servers these depend on (the same reach as ImpactAnalyzer: a failing
 * storage server takes down what mounts it). A project is its production:
 * the environments of kind production.
 *
 * Loads all checks once (a few hundred rows at most); scoped in
 * AppServiceProvider, so that is once per request or job.
 */
final class ProjectHealth
{
    public const int HOURS = 24;

    /** @var Collection<int, Check>|null id => check */
    private ?Collection $checks = null;

    /** @var Collection<int|string, Collection<int, Check>> server id => its checks, keyed by id */
    private Collection $checksByServer;

    /** @var Collection<int|string, Collection<int, Check>> hostname id => its checks, keyed by id */
    private Collection $checksByHostname;

    /** @var array<int, list<int>> environment id => check ids */
    private array $checksByEnvironment = [];

    /** @var array<int, list<int>> project id => production environment ids */
    private array $productionByProject = [];

    /** @var array<int, int> environment id => project id */
    private array $projectByEnvironment = [];

    /** @var array<int, true> ids of the projects that need no checks */
    private array $unmonitored = [];

    /** @var array<int, list<int>> project id => the servers its production runs on, and those they depend on */
    private array $serversByProject = [];

    /** @var array<int, list<int>> project id => the servers its production runs on itself */
    private array $directServersByProject = [];

    /** @var array<int, list<array{up: int, down: int}>>|null */
    private ?array $history = null;

    /** @var array<int, int>|null server id => network id, for the servers in a network */
    private ?array $networkByServer = null;

    private ?CarbonImmutable $firstHour = null;

    /** @var array<int, int>|null check id => ms */
    private ?array $responseTimes = null;

    public function __construct(private readonly CheckHistory $checkHistory) {}

    /**
     * @param  bool  $history  with the hour bars, uptime and response time; without, only the state (cheaper)
     */
    public function forProject(Project|int $project, bool $history = true): HealthReport
    {
        $this->load();

        $id = $project instanceof Project ? $project->id : $project;

        return $this->assess($this->checksOf($this->productionByProject[$id] ?? []), $history, isset($this->unmonitored[$id]));
    }

    /**
     * @param  bool  $history  with the hour bars, uptime and response time; without, only the state (cheaper)
     */
    public function forEnvironment(Environment|int $environment, bool $history = true): HealthReport
    {
        $this->load();

        $id = $environment instanceof Environment ? $environment->id : $environment;
        $projectId = $environment instanceof Environment ? $environment->project_id : ($this->projectByEnvironment[$id] ?? null);

        return $this->assess($this->checksOf([$id]), $history, $projectId !== null && isset($this->unmonitored[$projectId]));
    }

    /**
     * The checks that count for a project's production (keyed by id), with
     * their server: those of its hostnames, its servers and what they need.
     *
     * @return Collection<int, Check>
     */
    public function productionChecks(Project|int $project): Collection
    {
        $this->load();

        return $this->checksOf($this->productionByProject[$project instanceof Project ? $project->id : $project] ?? []);
    }

    /**
     * The servers a project's production needs: those it runs on, and those
     * these depend on (the reach of its checks, and of its incidents).
     *
     * @return list<int>
     */
    public function productionServerIds(Project|int $project): array
    {
        $this->load();

        return $this->serversByProject[$project instanceof Project ? $project->id : $project] ?? [];
    }

    /**
     * The networks the servers of a project's production stand in: an
     * incident there (the network's internet line is down) is one of its
     * incidents too.
     *
     * @return list<int>
     */
    public function productionNetworkIds(Project|int $project): array
    {
        $this->networkByServer ??= Server::query()->whereNotNull('network_id')->pluck('network_id', 'id')->all();

        return array_values(array_unique(array_intersect_key($this->networkByServer, array_flip($this->productionServerIds($project)))));
    }

    /**
     * Only the servers a project's production runs on itself.
     *
     * @return list<int>
     */
    public function directProductionServerIds(Project|int $project): array
    {
        $this->load();

        return $this->directServersByProject[$project instanceof Project ? $project->id : $project] ?? [];
    }

    /**
     * The server's own checks (ping, a port): not those of what runs on it.
     * Only the state: the map shows no hour bars.
     */
    public function forServer(int $serverId): HealthReport
    {
        $this->load();

        return $this->assess($this->checksByServer->get($serverId, new Collection), history: false);
    }

    /**
     * Only the state, like forServer().
     */
    public function forHostname(int $hostnameId): HealthReport
    {
        $this->load();

        return $this->assess($this->checksByHostname->get($hostnameId, new Collection), history: false);
    }

    /**
     * @param  list<int>  $environmentIds
     * @return Collection<int, Check>
     */
    private function checksOf(array $environmentIds): Collection
    {
        $ids = [];

        foreach ($environmentIds as $environmentId) {
            $ids = [...$ids, ...($this->checksByEnvironment[$environmentId] ?? [])];
        }

        // Not only(): on an Eloquent collection that renumbers the keys, and
        // these are check ids.
        return $this->checks->intersectByKeys(array_flip($ids));
    }

    /**
     * @param  Collection<int, Check>  $checks
     * @param  bool  $unmonitored  no checks is deliberate (Project::$unmonitored), not a gap
     */
    private function assess(Collection $checks, bool $history = true, bool $unmonitored = false): HealthReport
    {
        $active = $checks->where('enabled', true);

        $down = $active->filter(fn (Check $check): bool => $check->state === CheckState::Down && $check->type->measuresAvailability());

        $doubt = $active->diffKeys($down)->filter(fn (Check $check): bool => $check->state === CheckState::Down
            || $check->state === CheckState::Pending
            || $check->isStale());

        $state = match (true) {
            // Checks that are there count, also on a project that needs none.
            $checks->isEmpty() => $unmonitored ? HealthState::Unmonitored : HealthState::Unwatched,
            $active->isEmpty() => HealthState::Paused,
            $down->isNotEmpty() => HealthState::Down,
            $doubt->isNotEmpty() => HealthState::Degraded,
            $active->contains(fn (Check $check): bool => $check->state === CheckState::Up) => HealthState::Up,
            default => HealthState::Unknown,
        };

        // Oldest trouble first: the first problem is the one "since" is about.
        $down = $down->sortBy(fn (Check $check): string => (string) $this->troubleSince($check));
        $doubt = $doubt->sortBy(fn (Check $check): string => (string) $this->troubleSince($check));

        $since = match ($state) {
            HealthState::Down => $this->troubleSince($down->first()),
            HealthState::Degraded => $this->troubleSince($doubt->first()),
            default => null,
        };

        $responseMs = null;
        [$hours, $uptime] = [array_fill(0, self::HOURS, null), null];

        if ($history) {
            $this->responseTimes ??= $this->checkHistory->responseTimes(
                $this->checks->filter(fn (Check $check): bool => $check->timesThePage())->keys()->all(),
            );

            $responseMs = $active
                ->filter(fn (Check $check): bool => $check->type->isHttp() && $check->state === CheckState::Up && isset($this->responseTimes[$check->id]))
                ->avg(fn (Check $check): int => $this->responseTimes[$check->id]);

            [$hours, $uptime] = $this->hours($active->filter(fn (Check $check): bool => $check->type->measuresAvailability()));
        }

        return new HealthReport(
            state: $state,
            since: $since,
            problems: $down->merge($doubt)->values(),
            responseMs: $responseMs === null ? null : (int) round($responseMs),
            hours: $hours,
            uptime: $uptime,
            firstHour: $this->firstHour,
        );
    }

    /**
     * Since when a check is a problem: its last state change, or for one
     * without recent results, its last result.
     */
    private function troubleSince(Check $check): ?CarbonImmutable
    {
        return $check->isStale() ? ($check->last_checked_at ?? $check->created_at) : $check->last_state_changed_at;
    }

    /**
     * The hour bars (the worst of these checks per hour) and the uptime,
     * their average.
     *
     * @param  Collection<int, Check>  $checks
     * @return array{0: list<?float>, 1: ?float}
     */
    private function hours(Collection $checks): array
    {
        $hours = CheckHistory::worstPerHour($this->history(), $checks->keys(), self::HOURS);

        $measured = array_filter($hours, fn (?float $share): bool => $share !== null);

        return [$hours, $measured === [] ? null : round(array_sum($measured) / count($measured) * 100, 2)];
    }

    /**
     * Hour counts of every active availability check, once.
     *
     * @return array<int, list<array{up: int, down: int}>>
     */
    private function history(): array
    {
        return $this->history ??= $this->checkHistory->hourly(
            $this->checks
                ->filter(fn (Check $check): bool => $check->enabled && $check->type->measuresAvailability())
                ->keys()
                ->all(),
            self::HOURS,
            $this->firstHour,
        );
    }

    private function load(): void
    {
        if ($this->checks !== null) {
            return;
        }

        $this->checks = Check::query()->with('server:id,name')->get()->keyBy('id');
        $this->checksByServer = $this->checks->whereNotNull('server_id')->groupBy('server_id', preserveKeys: true);
        $this->checksByHostname = $this->checks->whereNotNull('hostname_id')->groupBy('hostname_id', preserveKeys: true);
        $this->firstHour = CheckHistory::firstHour(self::HOURS);

        $hostnameEnvironments = Hostname::query()->whereNotNull('environment_id')->pluck('environment_id', 'id');

        $serverEnvironments = EnvironmentServer::query()
            ->toBase()
            ->get(['environment_id', 'server_id'])
            ->groupBy('server_id')
            ->map(fn (Collection $rows): array => $rows->pluck('environment_id')->map(fn (mixed $id): int => (int) $id)->all());

        $dependencies = ServerDependency::query()->get(['id', 'server_id', 'depends_on_id']);
        $reach = [];

        foreach ($this->checks as $check) {
            if ($check->hostname_id !== null) {
                $environmentIds = array_filter([$hostnameEnvironments[$check->hostname_id] ?? null]);
            } elseif ($check->server_id === null) {
                // Neither: only the form insists on one of the two.
                continue;
            } else {
                // The environments on this server and on every server that needs it.
                $reach[$check->server_id] ??= collect(array_keys(ImpactAnalyzer::chains($dependencies, $check->server_id)))
                    ->flatMap(fn (int $serverId): array => $serverEnvironments[$serverId] ?? [])
                    ->unique()
                    ->all();

                $environmentIds = $reach[$check->server_id];
            }

            foreach ($environmentIds as $environmentId) {
                $this->checksByEnvironment[(int) $environmentId][] = $check->id;
            }
        }

        foreach (Environment::query()->toBase()->get(['id', 'project_id', 'kind']) as $environment) {
            $this->projectByEnvironment[(int) $environment->id] = (int) $environment->project_id;

            if ($environment->kind === EnvironmentKind::Production->value) {
                $this->productionByProject[(int) $environment->project_id][] = (int) $environment->id;
            }
        }

        $this->unmonitored = array_fill_keys(Project::query()->where('unmonitored', true)->pluck('id')->all(), true);

        $serversByEnvironment = [];

        foreach ($serverEnvironments as $serverId => $environmentIds) {
            foreach ($environmentIds as $environmentId) {
                $serversByEnvironment[$environmentId][] = (int) $serverId;
            }
        }

        foreach ($this->productionByProject as $projectId => $environmentIds) {
            $direct = array_values(array_unique(array_merge([], ...array_map(fn (int $id): array => $serversByEnvironment[$id] ?? [], $environmentIds))));

            $this->directServersByProject[$projectId] = $direct;
            $this->serversByProject[$projectId] = ImpactAnalyzer::upstream($dependencies, $direct);
        }
    }
}
