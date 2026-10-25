<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\HealthState;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Health\ProjectHealth;
use App\Services\Health\ProjectIncidents;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'environments']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Project'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Project $record): string => $record->client->name ?? __('Own project')),
                TextColumn::make('attention')
                    ->label(__('Attention'))
                    ->badge()
                    // The same state as on the dashboard tiles (ProjectHealth), but
                    // per environment: a staging outage shows here too.
                    ->state(fn (Project $record): array => self::attention($record))
                    // A state badge starts with its label; an environment name never decides the colour.
                    ->color(fn (string $state, Project $record): string => match (true) {
                        str_starts_with($state, HealthState::Down->getLabel().' · ') => 'danger',
                        str_starts_with($state, HealthState::Degraded->getLabel().' · ') => 'warning',
                        $state === self::openIncidents(app(ProjectIncidents::class)->open($record)->count()) => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('tags')->label(__('Stack'))->badge()->limitList(3)->toggleable(),
                TextColumn::make('environments_count')->label(__('Environments'))->counts('environments')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ProjectStatus::class),
                SelectFilter::make('type')->label(__('Type'))->options(ProjectType::class),
                TernaryFilter::make('client')
                    ->label(__('Client'))
                    ->placeholder(__('All projects'))
                    ->trueLabel(__('Client projects'))
                    ->falseLabel(__('Own projects'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('client_id'),
                        false: fn (Builder $query) => $query->whereNull('client_id'),
                    ),
                SelectFilter::make('client_id')->label(__('Which client'))->relationship('client', 'name')->searchable()->preload(),
                TernaryFilter::make('on_status_screen')->label(__('On the status screen')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    /**
     * "Outage · Production", "Needs attention · Staging", "1 open incident".
     *
     * @return list<string>
     */
    public static function attention(Project $project): array
    {
        $health = app(ProjectHealth::class);
        $incidents = app(ProjectIncidents::class);

        $states = $project->environments
            ->map(fn (Environment $environment): array => [$environment, $health->forEnvironment($environment, history: false)->state])
            ->filter(fn (array $pair): bool => in_array($pair[1], [HealthState::Down, HealthState::Degraded], true))
            ->map(fn (array $pair): string => "{$pair[1]->getLabel()} · {$pair[0]->name}");

        $open = $incidents->open($project)->count();
        $monitoring = $incidents->monitoring($project)->count();

        return [
            ...$states->values()->all(),
            ...array_filter([
                $open > 0 ? self::openIncidents($open) : null,
                $monitoring > 0 ? trans_choice(':count incident being watched|:count incidents being watched', $monitoring) : null,
            ]),
        ];
    }

    private static function openIncidents(int $count): string
    {
        return trans_choice(':count open incident|:count open incidents', $count);
    }
}
