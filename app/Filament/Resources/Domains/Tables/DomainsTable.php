<?php

namespace App\Filament\Resources\Domains\Tables;

use App\Enums\CostPeriod;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Domain;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'registrar']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Domain'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Domain $record): string => $record->client->name ?? __('Own domain')),
                TextColumn::make('registrar.name')->label(__('Registrar'))->placeholder('—'),
                TextColumn::make('expires_on')->label(__('Expires'))->date(__('formats.date'))->sortable()->placeholder('—'),
                // Without a contract, a domain is a local one (Domain::hasContract()).
                RenewalSchema::statusColumn()->placeholder(__('Local')),
                CostSchema::column()->toggleable(),
                IconColumn::make('auto_renews')
                    ->label(__('Automatic'))
                    ->state(fn (Domain $record): ?bool => $record->hasContract() ? $record->auto_renews : null)
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('hostnames_count')->label(__('Hostnames'))->counts('hostnames')->toggleable(),
                IconColumn::make('local_only')->label(__('Local'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                RenewalSchema::attentionFilter(Domain::class),
                RenewalSchema::statusFilter(Domain::class),
                CostSchema::missingFilter(),
                SelectFilter::make('registrar_id')->label(__('Registrar'))->relationship('registrar', 'name')->preload(),
                TernaryFilter::make('client')
                    ->label(__('Belongs to'))
                    ->placeholder(__('All domains'))
                    ->trueLabel(__('Client domains'))
                    ->falseLabel(__('Own domains'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('client_id'),
                        false: fn (Builder $query) => $query->whereNull('client_id'),
                    ),
            ])
            ->recordActions([
                RenewalSchema::renewAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RenewalSchema::renewBulkAction(),
                    CostSchema::bulkAction(CostPeriod::Year),
                ]),
            ]);
    }
}
