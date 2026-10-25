<?php

namespace App\Services\Map;

use App\Enums\EnvironmentKind;
use App\Enums\HealthState;
use App\Enums\ProjectStatus;
use App\Enums\ServerStatus;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Services\Health\HealthReport;
use App\Services\Health\ProjectHealth;
use App\Services\Impact\ImpactAnalyzer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The map of what hangs together, in columns: provider or network → server
 * → environment (per project) → hostname → domain. A server in a local
 * network hangs from that network as a VPS hangs from its provider. Each column is
 * sorted by the average place of its neighbours, so few lines cross; what
 * is linked to nothing goes to the bottom.
 *
 * Filtered (production only, one project or client), it shows the chosen
 * environments and what they need: their servers, the servers those depend
 * on, the providers and networks, their hostnames and domains.
 */
final class InfrastructureMap
{
    /**
     * @return array<string, string> column => heading
     */
    public static function columns(): array
    {
        return [
            'providers' => __('Provider / network'),
            'servers' => __('Server'),
            'environments' => __('Environment'),
            'hostnames' => __('Hostname'),
            'domains' => __('Domain'),
        ];
    }

    public function __construct(private readonly ProjectHealth $health) {}

    /**
     * @return array{columns: array<string, list<array<string, mixed>>>, groups: list<array<string, mixed>>, edges: list<array{from: string, to: string, label: ?string, kind: string}>}
     */
    public function build(bool $productionOnly = false, ?int $projectId = null, ?int $clientId = null): array
    {
        $filtered = $productionOnly || $projectId !== null || $clientId !== null;

        $projects = Project::query()
            ->with(['client', 'environments' => fn ($query) => $productionOnly ? $query->where('kind', EnvironmentKind::Production) : $query])
            ->where('status', '!=', ProjectStatus::Archived)
            ->when($projectId, fn (Builder $query) => $query->whereKey($projectId))
            ->when($clientId, fn (Builder $query) => $query->where('client_id', $clientId))
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project): bool => $project->environments->isNotEmpty());

        /** @var Collection<int, Environment> $environments */
        $environments = $projects->flatMap(fn (Project $project) => $project->environments)->keyBy('id');
        $assignments = EnvironmentServer::query()->toBase()->get(['environment_id', 'server_id', 'role']);
        $dependencies = ServerDependency::query()->get(['id', 'server_id', 'depends_on_id', 'kind']);
        $allServers = Server::query()->with(['provider', 'network'])->orderBy('name')->get()->keyBy('id');

        // Servers: those the environments run on, then what these need.
        $direct = $assignments->whereIn('environment_id', $environments->keys())->pluck('server_id')->map(fn (mixed $id): int => (int) $id)->unique();
        $serverIds = $filtered ? ImpactAnalyzer::upstream($dependencies, $direct->values()->all()) : $allServers->keys()->all();
        $servers = $allServers->intersectByKeys(array_flip($serverIds));

        $hostnames = Hostname::query()
            ->orderBy('fqdn')
            ->get(['id', 'domain_id', 'environment_id', 'fqdn', 'purpose'])
            ->filter(fn (Hostname $hostname): bool => $hostname->environment_id === null ? ! $filtered : $environments->has($hostname->environment_id))
            ->keyBy('id');

        $domains = Domain::query()
            ->with('registrar')
            ->orderBy('name')
            ->get()
            ->when($filtered, fn (Collection $domains) => $domains->whereIn('id', $hostnames->pluck('domain_id')))
            ->keyBy('id');

        // Positions, column by column, from the environments outwards.
        $environmentPlace = $this->places($environments->keys()->all());

        $environmentsByServer = $assignments->groupBy('server_id')->map(fn (Collection $rows): array => $rows->pluck('environment_id')->map(fn (mixed $id): int => (int) $id)->all());
        $dependentsByServer = $dependencies->groupBy('depends_on_id')->map(fn (Collection $rows): array => $rows->pluck('server_id')->all());

        $serverPlace = $this->places($this->sortByNeighbours(
            $servers->keys()->all(),
            fn (int $serverId): array => array_values(array_filter(array_map(
                fn (int $environmentId): ?float => $environmentPlace[$environmentId] ?? null,
                $environmentsByServer[$serverId] ?? [],
            ), fn (?float $place): bool => $place !== null)),
            fn (int $id): string => $servers[$id]->name,
            $dependentsByServer->all(),
        ));

        // Providers and networks share the first column, sorted together:
        // node id => what it is and the servers that hang from it.
        $providers = $servers->pluck('provider')->filter()->unique('id')->keyBy('id');
        $networks = $servers->pluck('network')->filter()->unique('id')->keyBy('id');
        $upstream = collect([
            ...$providers->mapWithKeys(fn (Provider $provider): array => ["provider-{$provider->id}" => ['model' => $provider, 'servers' => $servers->where('provider_id', $provider->id)]]),
            ...$networks->mapWithKeys(fn (Network $network): array => ["network-{$network->id}" => ['model' => $network, 'servers' => $servers->where('network_id', $network->id)]]),
        ]);

        $upstreamPlace = $this->places($this->sortByNeighbours(
            $upstream->keys()->all(),
            fn (string $key): array => $upstream[$key]['servers']->map(fn (Server $server): float => $serverPlace[$server->id])->values()->all(),
            fn (string $key): string => $upstream[$key]['model']->name,
        ));

        $hostnamePlace = $this->places($hostnames
            ->sortBy(fn (Hostname $hostname): string => sprintf('%09.6f', $environmentPlace[$hostname->environment_id] ?? 999).$hostname->fqdn)
            ->keys()
            ->all());

        $hostnamesByDomain = $hostnames->groupBy('domain_id');
        $domainPlace = $this->places($this->sortByNeighbours(
            $domains->keys()->all(),
            fn (int $domainId): array => $hostnamesByDomain->get($domainId, new Collection)->map(fn (Hostname $hostname): float => $hostnamePlace[$hostname->id])->values()->all(),
            fn (int $id): string => $domains[$id]->name,
        ));

        return [
            'columns' => [
                'providers' => array_map(fn (string $key): array => $upstream[$key]['model'] instanceof Provider
                    ? $this->providerNode($upstream[$key]['model'], $upstream[$key]['servers']->count())
                    : $this->networkNode($upstream[$key]['model']), array_keys($upstreamPlace)),
                'servers' => array_map(fn (int $id): array => $this->serverNode($servers[$id]), array_keys($serverPlace)),
                'hostnames' => array_map(fn (int $id): array => $this->hostnameNode($hostnames[$id]), array_keys($hostnamePlace)),
                'domains' => array_map(fn (int $id): array => $this->domainNode($domains[$id]), array_keys($domainPlace)),
            ],
            'groups' => $projects->map(fn (Project $project): array => [
                'id' => "project-{$project->id}",
                'label' => $project->name,
                'sub' => $project->client?->name ?? __('Own project'),
                'url' => ProjectResource::getUrl('view', ['record' => $project]),
                'nodes' => $project->environments->map(fn (Environment $environment): array => $this->environmentNode($environment, $project))->all(),
            ])->values()->all(),
            'edges' => $this->edges($providers, $networks, $servers, $environments, $hostnames, $domains, $assignments, $dependencies),
        ];
    }

    /**
     * Sorted by the average place of their neighbours (the barycentre),
     * then by name; without neighbours at the bottom. An item without
     * neighbours of its own that others pull at (storage that app servers
     * need) takes their average place, also along a chain (backup ←
     * storage ← app): repeated until nothing moves.
     *
     * @template TKey of int|string
     *
     * @param  list<TKey>  $ids
     * @param  callable(TKey): list<float>  $neighbourPlaces
     * @param  callable(TKey): string  $name
     * @param  array<TKey, list<TKey>>  $pulledBy  id => the ids whose place pulls it
     * @return list<TKey>
     */
    private function sortByNeighbours(array $ids, callable $neighbourPlaces, callable $name, array $pulledBy = []): array
    {
        $centre = [];

        foreach ($ids as $id) {
            $places = $neighbourPlaces($id);
            $centre[$id] = $places === [] ? null : array_sum($places) / count($places);
        }

        $free = array_keys(array_filter($centre, fn (?float $place): bool => $place === null));

        for ($pass = 0; $pass < count($ids) && $free !== []; $pass++) {
            $moved = false;

            foreach ($free as $id) {
                $places = array_values(array_filter(
                    array_map(fn (int $other): ?float => $centre[$other] ?? null, $pulledBy[$id] ?? []),
                    fn (?float $place): bool => $place !== null,
                ));

                // Just after them, so it sits next to what needs it.
                $place = $places === [] ? null : array_sum($places) / count($places) + 0.0001;

                if ($place !== $centre[$id]) {
                    $centre[$id] = $place;
                    $moved = true;
                }
            }

            if (! $moved) {
                break;
            }
        }

        usort($ids, fn (int|string $a, int|string $b): int => [$centre[$a] === null, $centre[$a], $name($a)] <=> [$centre[$b] === null, $centre[$b], $name($b)]);

        return $ids;
    }

    /**
     * @template TKey of int|string
     *
     * @param  list<TKey>  $ids  in their order
     * @return array<TKey, float> id => place between 0 and 1, in the same order
     */
    private function places(array $ids): array
    {
        $count = count($ids);
        $places = [];

        foreach (array_values($ids) as $index => $id) {
            $places[$id] = ($index + 0.5) / max(1, $count);
        }

        return $places;
    }

    /** @return array<string, mixed> */
    private function providerNode(Provider $provider, int $count): array
    {
        return $this->node("provider-{$provider->id}", $provider->name, trans_choice(':count server|:count servers', $count), ProviderResource::getUrl('view', ['record' => $provider]));
    }

    /** @return array<string, mixed> */
    private function networkNode(Network $network): array
    {
        $node = $this->node("network-{$network->id}", $network->name, $network->subnet ?? $network->location, NetworkResource::getUrl('view', ['record' => $network]));

        return [...$node, 'subMono' => $network->subnet !== null];
    }

    /** @return array<string, mixed> */
    private function serverNode(Server $server): array
    {
        // The private address for a server that has no other, such as one in a local network.
        $address = $server->public_ipv4 ?? $server->public_ipv6 ?? $server->provider_hostname ?? $server->private_ip;
        $node = [...$this->node("server-{$server->id}", $server->name, $address, ServerResource::getUrl('view', ['record' => $server]), mono: true), 'subMono' => true];

        if ($server->status === ServerStatus::Decommissioned) {
            return [...$node, 'color' => 'gray', 'status' => $server->status->getLabel(), 'selectable' => true];
        }

        return [...$this->withHealth($node, $this->health->forServer($server->id)), 'selectable' => true];
    }

    /** @return array<string, mixed> */
    private function environmentNode(Environment $environment, Project $project): array
    {
        $node = $this->node("environment-{$environment->id}", $environment->name, null, ProjectResource::getUrl('view', ['record' => $project]));

        return $this->withHealth($node, $this->health->forEnvironment($environment, history: false));
    }

    /** @return array<string, mixed> */
    private function hostnameNode(Hostname $hostname): array
    {
        $node = $this->node("hostname-{$hostname->id}", $hostname->fqdn, $hostname->purpose, HostnameResource::getUrl('view', ['record' => $hostname]), mono: true);

        return $this->withHealth($node, $this->health->forHostname($hostname->id));
    }

    /** @return array<string, mixed> */
    private function domainNode(Domain $domain): array
    {
        $node = $this->node(
            "domain-{$domain->id}",
            $domain->name,
            $domain->hasContract()
                ? implode(' · ', array_filter([$domain->registrar?->name, $domain->expires_on?->translatedFormat(__('formats.date'))]))
                : __('Local'),
            DomainResource::getUrl('view', ['record' => $domain]),
            mono: true,
        );

        // A local domain renews nowhere: no status to show.
        if (! $domain->hasContract()) {
            return $node;
        }

        $status = $domain->renewalStatus();

        return [...$node, 'color' => $status->getColor(), 'status' => $status->getLabel()];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withHealth(array $node, HealthReport $report): array
    {
        return [...$node, 'color' => $report->state->getColor(), 'status' => $report->state->getLabel(), 'down' => $report->state === HealthState::Down];
    }

    /** @return array<string, mixed> */
    private function node(string $id, string $label, ?string $sub, string $url, bool $mono = false): array
    {
        return ['id' => $id, 'label' => $label, 'sub' => $sub, 'url' => $url, 'mono' => $mono, 'subMono' => false, 'color' => null, 'status' => null, 'down' => false, 'selectable' => false];
    }

    /**
     * Each edge points from what is needed to what needs it.
     *
     * @return list<array{from: string, to: string, label: ?string, title?: string, kind: string}>
     */
    private function edges(Collection $providers, Collection $networks, Collection $servers, Collection $environments, Collection $hostnames, Collection $domains, Collection $assignments, Collection $dependencies): array
    {
        $edges = [];

        foreach ($servers as $server) {
            if ($server->provider_id !== null && $providers->has($server->provider_id)) {
                $edges[] = ['from' => "provider-{$server->provider_id}", 'to' => "server-{$server->id}", 'label' => null, 'kind' => 'hosts'];
            }

            if ($server->network_id !== null && $networks->has($server->network_id)) {
                $edges[] = ['from' => "network-{$server->network_id}", 'to' => "server-{$server->id}", 'label' => null, 'kind' => 'network'];
            }
        }

        foreach ($dependencies as $dependency) {
            if ($servers->has($dependency->server_id) && $servers->has($dependency->depends_on_id)) {
                // Short beside the column; the whole kind on hovering.
                $edges[] = ['from' => "server-{$dependency->depends_on_id}", 'to' => "server-{$dependency->server_id}", 'label' => Str::limit($dependency->kind, 12), 'title' => $dependency->kind, 'kind' => 'depends'];
            }
        }

        foreach ($assignments as $assignment) {
            if ($servers->has($assignment->server_id) && $environments->has($assignment->environment_id)) {
                // One line, also for a server with two roles in the environment.
                $edges[] = ['from' => "server-{$assignment->server_id}", 'to' => "environment-{$assignment->environment_id}", 'label' => null, 'kind' => 'runs'];
            }
        }

        foreach ($hostnames as $hostname) {
            if ($hostname->environment_id !== null && $environments->has($hostname->environment_id)) {
                $edges[] = ['from' => "environment-{$hostname->environment_id}", 'to' => "hostname-{$hostname->id}", 'label' => null, 'kind' => 'points'];
            }

            if ($domains->has($hostname->domain_id)) {
                $edges[] = ['from' => "hostname-{$hostname->id}", 'to' => "domain-{$hostname->domain_id}", 'label' => null, 'kind' => 'domain'];
            }
        }

        return array_values(array_unique($edges, SORT_REGULAR));
    }
}
