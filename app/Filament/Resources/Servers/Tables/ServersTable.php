<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Enums\MaintenanceTask;
use App\Enums\ServerStatus;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Server;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['provider', 'network', 'assignments'])->withLastDone(MaintenanceTask::Updates))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Server'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Server $record): ?string => $record->provider?->name ?? $record->network?->name),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('public_ipv4')->label('IPv4')->fontFamily(FontFamily::Mono)->searchable()->copyable()->placeholder('—'),
                TextColumn::make('os')
                    ->label('OS')
                    ->state(fn (Server $record): ?string => trim("{$record->os_name} {$record->os_version}") ?: null)
                    ->placeholder('—'),
                TextColumn::make('location')->label(__('Location'))->placeholder('—')->toggleable(),
                TextColumn::make('environments_count')
                    ->label(__('Environments'))
                    ->state(fn (Server $record): int => $record->assignments->unique('environment_id')->count()),
                CostSchema::column()->toggleable(),
                TextColumn::make('expires_on')->label(__('Contract until'))->date(__('formats.date'))->sortable()->placeholder('—')->toggleable(),
                RenewalSchema::statusColumn()->toggleable(),
                TextColumn::make('last_updates_at')
                    ->label(__('Last updates'))
                    // A moment, not a date column: date() alone would give the UTC day.
                    ->date(__('formats.date'), timezone: config('herdbook.display_timezone'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('last_updates_at', $direction))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ServerStatus::class),
                SelectFilter::make('provider_id')->label(__('Provider'))->relationship('provider', 'name')->preload(),
                SelectFilter::make('network_id')->label(__('Network'))->relationship('network', 'name')->preload(),
                RenewalSchema::attentionFilter(Server::class),
                RenewalSchema::statusFilter(Server::class),
                CostSchema::missingFilter(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RenewalSchema::renewBulkAction(),
                ]),
            ]);
    }
}
