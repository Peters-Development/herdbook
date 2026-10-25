<?php

namespace App\Filament\Widgets;

use App\Enums\HealthState;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Services\Health\ProjectHealth;
use App\Services\Health\ProjectIncidents;
use Filament\Widgets\Widget;

/**
 * Every project as a tile, coloured by its production: what is wrong comes
 * first, and each tile shows where the project runs. A project that needs no
 * checks (and has none) has nothing to show here and gets no tile.
 */
class ProjectBoard extends Widget
{
    protected static ?int $sort = -2;

    // With the page, like StatusOverview: one ProjectHealth for both.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.project-board';

    /**
     * @return array{tiles: list<array<string, mixed>>, unmonitored: int}
     */
    protected function getViewData(): array
    {
        $health = app(ProjectHealth::class);
        $incidents = app(ProjectIncidents::class);
        $serverNames = Server::query()->pluck('name', 'id');

        [$unmonitored, $projects] = Project::query()
            ->where('status', '!=', ProjectStatus::Archived)
            ->with(['client', 'environments'])
            ->orderBy('name')
            ->get()
            ->partition(fn (Project $project): bool => $health->forProject($project, history: false)->state === HealthState::Unmonitored);

        $tiles = $projects
            ->map(function (Project $project) use ($health, $incidents, $serverNames): array {
                $direct = $health->directProductionServerIds($project);

                return [
                    'project' => $project,
                    'health' => $health->forProject($project),
                    // The tile is about how production does: its status page.
                    'url' => ProjectResource::getUrl('status', ['record' => $project]),
                    'environments' => $project->environments->map(fn (Environment $environment): array => [
                        'name' => $environment->name,
                        'state' => $health->forEnvironment($environment, history: false)->state,
                    ])->all(),
                    // Where production runs, and (dimmed) what those servers depend on.
                    'servers' => collect($direct)->map(fn (int $id): ?string => $serverNames[$id] ?? null)->filter()->sort()->values()->all(),
                    'upstream' => collect(array_diff($health->productionServerIds($project), $direct))->map(fn (int $id): ?string => $serverNames[$id] ?? null)->filter()->sort()->values()->all(),
                    'open' => $incidents->open($project)->count(),
                    'monitoring' => $incidents->monitoring($project)->count(),
                ];
            })
            ->sortBy(fn (array $tile): int => match ($tile['health']->state) {
                HealthState::Down => 0,
                HealthState::Degraded => 1,
                default => 2,
            })
            ->values()
            ->all();

        return ['tiles' => $tiles, 'unmonitored' => $unmonitored->count()];
    }
}
