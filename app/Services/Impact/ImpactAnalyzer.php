<?php

namespace App\Services\Impact;

use App\Models\EnvironmentServer;
use App\Models\Server;
use App\Models\ServerDependency;
use Illuminate\Support\Collection;

/**
 * "What does this affect?": which environments go down when a server (or
 * a whole network of them) does. Follows server dependencies (NFS,
 * WireGuard, ...) upwards to every server that needs the failing one,
 * directly or indirectly.
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

        return EnvironmentServer::query()
            ->with(['environment.project', 'server'])
            ->whereIn('server_id', array_keys($chains))
            ->get()
            ->map(fn (EnvironmentServer $assignment): ImpactedEnvironment => new ImpactedEnvironment(
                $assignment->environment,
                $assignment->server,
                $assignment->role,
                $chains[$assignment->server_id],
            ))
            ->sortBy(fn (ImpactedEnvironment $impact): int => count($impact->chain))
            ->unique(fn (ImpactedEnvironment $impact): int => $impact->environment->id)
            ->sortBy(fn (ImpactedEnvironment $impact): string => $impact->environment->full_name)
            ->values();
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
