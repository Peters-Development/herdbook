<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Enums\CheckState;
use App\Enums\RenewalStatus;
use App\Filament\Resources\Hostnames\HostnameResource;
use App\Models\Hostname;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The hostnames of all environments of the project. They are created on
 * their domain (or under Hostnames): a hostname needs a domain.
 */
class HostnamesRelationManager extends RelationManager
{
    protected static string $relationship = 'hostnames';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Hostnames');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('fqdn')
            ->modelLabel(__('hostname'))
            ->pluralModelLabel(__('hostnames'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['environment', 'checks', 'domain.registrar']))
            ->defaultSort('fqdn')
            ->columns([
                TextColumn::make('fqdn')->label(__('Hostname'))->fontFamily(FontFamily::Mono)->searchable(),
                TextColumn::make('monitoring')
                    ->label(__('Monitoring'))
                    ->badge()
                    ->state(fn (Hostname $record): ?CheckState => $record->monitoringState())
                    ->placeholder(__('Off')),
                TextColumn::make('environment.name')->label(__('Environment')),
                TextColumn::make('purpose')->label(__('Purpose'))->placeholder('—'),
                TextColumn::make('redirects_to')->label(__('Redirects to'))->fontFamily(FontFamily::Mono)->placeholder('—'),
                TextColumn::make('domain_renewal')
                    ->label(__('Domain'))
                    ->badge()
                    // A local domain renews nowhere (Domain::hasContract()). A
                    // state, not a placeholder: an empty cell drops its description.
                    ->state(fn (Hostname $record): RenewalStatus|string => $record->domain->hasContract() ? $record->domain->renewalStatus() : __('Local'))
                    ->color(fn (RenewalStatus|string $state): string => $state instanceof RenewalStatus ? $state->getColor() : 'gray')
                    ->description(fn (Hostname $record): string => $record->domain->name),
                IconColumn::make('behind_proxy')->label(__('Behind a proxy'))->boolean(),
                IconColumn::make('local_only')->label(__('Local'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading(__('No hostnames yet'))
            ->emptyStateDescription(__('Add hostnames on their domain (Inventory → Domains) and point them at an environment of this project.'))
            ->recordUrl(fn (Hostname $record): string => HostnameResource::getUrl('view', ['record' => $record]));
    }
}
