<?php

namespace App\Filament\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Services\Impact\ImpactAnalyzer;
use App\Services\Impact\ImpactedEnvironment;
use App\Services\Map\InfrastructureMap;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;

/**
 * What hangs together: providers and networks, servers, environments,
 * hostnames and domains in columns, with the lines between them. The nodes
 * are rendered here; resources/js/herdbook-map.js draws the lines and does
 * the hovering.
 */
class Map extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'map';

    protected string $view = 'filament.pages.map';

    #[Url(as: 'production')]
    public bool $productionOnly = false;

    #[Url(as: 'project')]
    public ?int $projectId = null;

    #[Url(as: 'client')]
    public ?int $clientId = null;

    public static function getNavigationLabel(): string
    {
        return __('Map');
    }

    public function getTitle(): string
    {
        return __('Map');
    }

    /**
     * What a server takes down with it, for the panel next to the map: the
     * environments of projects that are on the map (not archived ones).
     * Renderless: the map itself does not change.
     *
     * @return array{server: string, url: ?string, missing: bool, servers?: int, nodes: list<string>, items: list<array{key: int, label: string, path: ?string, url: string}>}
     */
    #[Renderless]
    public function impact(int $serverId): array
    {
        $server = Server::query()->find($serverId);

        // Deleted in another tab since the map was drawn.
        if ($server === null) {
            return ['server' => __('this server'), 'url' => null, 'missing' => true, 'servers' => 0, 'nodes' => [], 'items' => []];
        }

        $impacts = app(ImpactAnalyzer::class)->forServer($server)
            ->reject(fn (ImpactedEnvironment $impact): bool => $impact->environment->project->status === ProjectStatus::Archived)
            ->values();

        // Every server that needs this one, also one without environments
        // (a backup server on its NFS): it goes down too.
        $servers = array_keys(ImpactAnalyzer::chains(ServerDependency::query()->get(['id', 'server_id', 'depends_on_id']), $server->id));

        $nodes = $impacts
            ->map(fn (ImpactedEnvironment $impact): string => "environment-{$impact->environment->id}")
            ->merge(array_map(fn (int $id): string => "server-{$id}", $servers));

        return [
            'server' => $server->name,
            'url' => ServerResource::getUrl('view', ['record' => $server]),
            'missing' => false,
            'servers' => count($servers) - 1,
            'nodes' => $nodes->unique()->values()->all(),
            'items' => $impacts->map(fn (ImpactedEnvironment $impact): array => [
                'key' => $impact->environment->id,
                'label' => $impact->environment->full_name,
                'path' => $impact->isDirect() ? null : $impact->path(),
                'url' => ProjectResource::getUrl('view', ['record' => $impact->environment->project_id]),
            ])->all(),
        ];
    }

    /**
     * A project of another client would leave an empty map.
     */
    public function updatedClientId(): void
    {
        if ($this->projectId !== null && $this->clientId !== null
            && ! Project::query()->whereKey($this->projectId)->where('client_id', $this->clientId)->exists()) {
            $this->projectId = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'map' => app(InfrastructureMap::class)->build($this->productionOnly, $this->projectId, $this->clientId),
            'columns' => InfrastructureMap::columns(),
            'projects' => Project::query()
                ->where('status', '!=', ProjectStatus::Archived)
                ->when($this->clientId, fn ($query) => $query->where('client_id', $this->clientId))
                ->orderBy('name')
                ->pluck('name', 'id'),
            'clients' => Client::query()->whereNull('archived_at')->orderBy('name')->pluck('name', 'id'),
            'filterKey' => md5(json_encode([$this->productionOnly, $this->projectId, $this->clientId])),
        ];
    }
}
