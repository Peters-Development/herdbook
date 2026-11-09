<?php

namespace App\Services\Health;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * The unresolved incidents of a project, wherever they are linked: to the
 * project itself, to a server its production needs (the same reach as its
 * state, ProjectHealth), or to the network such a server stands in. One
 * answer for the band, the tiles, the projects list and the status screen.
 * Open ones first, then by severity.
 *
 * Scoped (AppServiceProvider): loaded once per request.
 */
final class ProjectIncidents
{
    /** @var Collection<int, Incident>|null */
    private ?Collection $unresolved = null;

    public function __construct(private readonly ProjectHealth $health) {}

    /**
     * @return Collection<int, Incident> Open and Monitoring
     */
    public function for(Project|int $project): Collection
    {
        $reach = $this->reach([$project instanceof Project ? $project->id : $project]);

        return $this->unresolved()
            ->filter(fn (Incident $incident): bool => $incident->projects->whereIn('id', $reach['projects'])->isNotEmpty()
                || $incident->servers->whereIn('id', $reach['servers'])->isNotEmpty()
                || $incident->networks->whereIn('id', $reach['networks'])->isNotEmpty())
            ->values();
    }

    /**
     * What an incident is linked to when it belongs to any of these
     * projects, resolved or not: the projects, the servers their production
     * needs, and the networks those stand in.
     *
     * @param  list<int>  $projectIds
     * @return array{projects: list<int>, servers: list<int>, networks: list<int>}
     */
    public function reach(array $projectIds): array
    {
        $servers = [];
        $networks = [];

        foreach ($projectIds as $projectId) {
            $servers = [...$servers, ...$this->health->productionServerIds($projectId)];
            $networks = [...$networks, ...$this->health->productionNetworkIds($projectId)];
        }

        return ['projects' => array_values($projectIds), 'servers' => array_values(array_unique($servers)), 'networks' => array_values(array_unique($networks))];
    }

    /**
     * @return Collection<int, Incident>
     */
    public function open(Project|int $project): Collection
    {
        return $this->for($project)->where('status', IncidentStatus::Open)->values();
    }

    /**
     * Recovered, but not closed yet.
     *
     * @return Collection<int, Incident>
     */
    public function monitoring(Project|int $project): Collection
    {
        return $this->for($project)->where('status', IncidentStatus::Monitoring)->values();
    }

    /**
     * @return Collection<int, Incident>
     */
    private function unresolved(): Collection
    {
        return $this->unresolved ??= Incident::query()
            ->where('status', '!=', IncidentStatus::Resolved)
            ->with(['projects:id', 'servers:id', 'networks:id'])
            ->get()
            ->sortBy(fn (Incident $incident): string => ($incident->status === IncidentStatus::Open ? '0' : '1').$incident->severity->value)
            ->values();
    }
}
