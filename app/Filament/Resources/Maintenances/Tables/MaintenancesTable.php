<?php

namespace App\Filament\Resources\Maintenances\Tables;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Filament\Support\MaintenanceSchema;
use App\Models\Maintenance;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaintenancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['servers', 'projects', 'user']))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label(__('When'))->dateTime(__('formats.datetime'))->sortable(),
                TextColumn::make('title')
                    ->label(__('Maintenance'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (Maintenance $record): ?string => MaintenanceSchema::linkedTo($record)),
                MaintenanceSchema::tasksColumn(),
                MaintenanceSchema::statusColumn(),
                TextColumn::make('user.name')->label(__('By'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(MaintenanceStatus::class),
                Filter::make('overdue')->label(__('Overdue'))->query(fn (Builder $query): Builder => $query->overdue()),
                SelectFilter::make('tasks')
                    ->label(__('Work'))
                    ->options(MaintenanceTask::class)
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereJsonContains('tasks', $data['value'])),
                SelectFilter::make('servers')->label(__('Server'))->relationship('servers', 'name')->searchable()->preload(),
                SelectFilter::make('projects')->label(__('Project'))->relationship('projects', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                MaintenanceSchema::completeAction(),
                MaintenanceSchema::cancelAction(),
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
