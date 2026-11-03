<?php

namespace App\Services\Impact;

use App\Enums\ServerRole;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Server;
use App\Models\ServerDependency;
use Illuminate\Support\Collection;

/**
 * "What does this affect?": which environments go down when a server (or
 * a whole network of them) does. Follows server dependencies (NFS,
 * WireGuard, ...) upwards to every server that needs the failing one,
 * directly or indirectly, and the environments that use those servers
 * (usage(): they run on it, or the mail of their domain does).
 */
final class ImpactAnalyzer
{
    /**
     * Dependency chains longer than this are a modelling mistake, not a setup.
     */
    private const int MAX_DEPTH = 10;

    /**
     * One entry per environment, through the shortest chain.
     *
     * @return Collection<int, ImpactedEnvironment>
     */
    public function forServer(Server $server): Collection
    {
        return $this->forServers([$server->id]);
    }

    /**
     * When $serverIds go down together (the network they are in drops out):
     * one entry per environment, through the shortest chain.
     *
     * @param  list<int>  $serverIds
     * @return Collection<int, ImpactedEnvironment>
     */
    public function forServers(array $serverIds): Collection
    {
        if ($serverIds === []) {
            return new Collection;
        }

        // Tens of rows: all of them at once, the walk is in memory.
        $chains = self::chains(ServerDependency::query()->with(['server', 'dependsOn'])->get(), $serverIds);
        $uses = self::usage()->filter(fn (ServerUse $use): bool => isset($chains[$use->serverId]));

        $environments = Environment::query()->with('project')->findMany($uses->pluck('environmentId')->unique())->keyBy('id');
        $servers = Server::query()->findMany($uses->pluck('serverId')->unique())->keyBy('id');
        $domains = Domain::query()->findMany($uses->pluck('domainId')->filter()->unique())->keyBy('id');

        return $uses
            ->map(fn (ServerUse $use): ImpactedEnvironment => new ImpactedEnvironment(
                $environments[$use->environmentId],
                $servers[$use->serverId],
                $use->role,
                $chains[$use->serverId],
                $use->domainId === null ? null : $domains[$use->domainId],
            ))
            // The shortest chain, and running on it before mail through a domain.
            ->sortBy(fn (ImpactedEnvironment $impact): int => count($impact->chain) * 2 + ($impact->domain === null ? 0 : 1))
            ->unique(fn (ImpactedEnvironment $impact): int => $impact->environment->id)
            ->sortBy(fn (ImpactedEnvironment $impact): string => $impact->environment->full_name)
            ->values();
    }

    /**
     * Which environments use which server directly: those that run on it
     * (in a role), and those with a hostname whose domain has its mail there
     * (Domain::mailServer(), role Mail). The start of every reach: impact,
     * health, incidents and the map.
     *
     * @return Collection<int, ServerUse>
     */
    public static function usage(): Collection
    {
        $assignments = EnvironmentServer::query()
            ->toBase()
            ->get(['environment_id', 'server_id', 'role'])
            ->map(fn (object $row): ServerUse => new ServerUse((int) $row->environment_id, (int) $row->server_id, ServerRole::from($row->role)));

        $mail = Hostname::query()
            ->toBase()
            ->join('domains', 'domains.id', '=', 'hostnames.domain_id')
            ->whereNotNull('domains.mail_server_id')
            ->whereNotNull('hostnames.environment_id')
            ->distinct()
            ->get(['hostnames.environment_id', 'domains.mail_server_id', 'domains.id as domain_id'])
            ->map(fn (object $row): ServerUse => new ServerUse((int) $row->environment_id, (int) $row->mail_server_id, ServerRole::Mail, (int) $row->domain_id));

        return $assignments->concat($mail)->values();
    }

    /**
     * Every server that needs $serverIds, directly or indirectly, with the
     * chain towards the nearest of them ($serverIds themselves have an empty
     * one). Breadth-first, so each server is reached through its shortest
     * chain; a server seen before is not visited again (cycles end there).
     *
     * @param  Collection<int, ServerDependency>  $dependencies  all of them
     * @param  int|list<int>  $serverIds
     * @return array<int, list<ServerDependency>> server id => chain towards one of $serverIds
     */
    public static function chains(Collection $dependencies, int|array $serverIds): array
    {
        $chains = array_fill_keys((array) $serverIds, []);
        $frontier = array_keys($chains);

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $next = [];

            foreach ($dependencies->whereIn('depends_on_id', $frontier)->sortBy('id') as $dependency) {
                if (array_key_exists($dependency->server_id, $chains)) {
                    continue;
                }

                $chains[$dependency->server_id] = [$dependency, ...$chains[$dependency->depends_on_id]];
                $next[] = $dependency->server_id;
            }

            $frontier = $next;
        }

        return $chains;
    }

    /**
     * The other way round: the servers plus everything they depend on,
     * directly or indirectly (an app server's storage and database).
     *
     * @param  Collection<int, ServerDependency>  $dependencies  all of them
     * @param  list<int>  $serverIds
     * @return list<int>
     */
    public static function upstream(Collection $dependencies, array $serverIds): array
    {
        $seen = array_fill_keys($serverIds, true);
        $frontier = $serverIds;

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $next = [];

            foreach ($dependencies->whereIn('server_id', $frontier) as $dependency) {
                if (! isset($seen[$dependency->depends_on_id])) {
                    $seen[$dependency->depends_on_id] = true;
                    $next[] = $dependency->depends_on_id;
                }
            }

            $frontier = $next;
        }

        return array_keys($seen);
    }
}
