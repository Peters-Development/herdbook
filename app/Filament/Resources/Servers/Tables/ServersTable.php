<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Enums\HealthState;
use App\Enums\MaintenanceTask;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Server;
use App\Services\Health\HealthReport;
use App\Services\Health\ProjectHealth;
use App\Services\Impact\ServerUse;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['provider', 'network'])->withCount(['checks', 'mailDomains', 'dnsDomains'])->withLastDone(MaintenanceTask::Updates))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Server'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Server $record): ?string => $record->provider?->name ?? $record->network?->name),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('health')
                    ->label(__('Monitoring'))
                    ->badge()
                    ->state(fn (Server $record): ?HealthState => self::health($record)?->state)
                    ->placeholder('—')
                    ->tooltip(fn (Server $record): ?string => self::health($record)?->problems->pluck('name')->implode(', ') ?: null),
                TextColumn::make('public_ipv4')->label('IPv4')->fontFamily(FontFamily::Mono)->searchable()->copyable()->placeholder('—'),
                TextColumn::make('operating_system')->label('OS')->placeholder('—'),
                TextColumn::make('location')->label(__('Location'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('roles')
                    ->label(__('Roles'))
                    ->badge()
                    ->color('gray')
                    // Mail and DNS also for domains whose hostnames are in no environment.
                    ->state(fn (Server $record): array => app(ProjectHealth::class)->usesOf($record->id)
                        ->map(fn (ServerUse $use): string => $use->role->getLabel())
                        ->when($record->mail_domains_count > 0, fn ($roles) => $roles->push(ServerRole::Mail->getLabel()))
                        ->when($record->dns_domains_count > 0, fn ($roles) => $roles->push(ServerRole::Dns->getLabel()))
                        ->unique()->sort()->values()->all())
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('environments_count')
                    ->label(__('Environments'))
                    // Also those with their mail or DNS here, through their domain.
                    ->state(fn (Server $record): int => app(ProjectHealth::class)->usesOf($record->id)->unique('environmentId')->count())
                    ->description(fn (Server $record): ?string => collect([
                        $record->mail_domains_count > 0 ? trans_choice('mail of :count domain|mail of :count domains', $record->mail_domains_count) : null,
                        $record->dns_domains_count > 0 ? trans_choice('DNS of :count domain|DNS of :count domains', $record->dns_domains_count) : null,
                    ])->filter()->implode(' · ') ?: null),
                TextColumn::make('checks_count')->label(__('Checks'))->sortable()->toggleable(isToggledHiddenByDefault: true),
                CostSchema::column()->toggleable(),
                TextColumn::make('expires_on')->label(__('Contract until'))->date(__('formats.date'))->sortable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('health')
                    ->label(__('Monitoring'))
                    ->options(collect([HealthState::Down, HealthState::Degraded, HealthState::Up, HealthState::Unknown, HealthState::Paused, HealthState::Unwatched])
                        ->mapWithKeys(fn (HealthState $state): array => [$state->value => $state->getLabel()])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereKey(
                        Server::query()->get(['id', 'status'])->filter(fn (Server $server): bool => self::health($server)?->state->value === $data['value'])->modelKeys(),
                    )),
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

    /**
     * The server's own checks (ProjectHealth::forServer()), not those of what
     * runs on it. None for a server out of use: its checks are paused, and
     * having none is no gap (as on the map).
     */
    private static function health(Server $server): ?HealthReport
    {
        return $server->status === ServerStatus::Decommissioned ? null : app(ProjectHealth::class)->forServer($server->id);
    }
}
