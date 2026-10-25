<?php

namespace App\Filament\Resources\Incidents\Tables;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Project;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('projects'))
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                TextColumn::make('occurred_on')->label(__('Date'))->date(__('formats.date'))->sortable(),
                TextColumn::make('title')
                    ->label(__('Incident'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (Incident $record): ?string => $record->projects->map(fn (Project $project): string => $project->name)->implode(', ') ?: null),
                TextColumn::make('severity')->label(__('Severity'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(IncidentStatus::class),
                SelectFilter::make('severity')->label(__('Severity'))->options(IncidentSeverity::class),
                SelectFilter::make('projects')->label(__('Project'))->relationship('projects', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
