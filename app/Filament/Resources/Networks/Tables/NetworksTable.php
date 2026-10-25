<?php

namespace App\Filament\Resources\Networks\Tables;

use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Network;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NetworksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('provider'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Network'))->searchable()->sortable(),
                TextColumn::make('subnet')->label(__('Subnet'))->fontFamily(FontFamily::Mono)->searchable()->copyable()->placeholder('—'),
                TextColumn::make('location')->label(__('Location'))->placeholder('—'),
                TextColumn::make('provider.name')->label(__('Internet provider'))->placeholder('—')->toggleable(),
                TextColumn::make('servers_count')->label(__('Servers'))->counts('servers'),
                TextColumn::make('addresses_count')->label(__('Addresses'))->counts('addresses'),
                CostSchema::column()->toggleable(),
                TextColumn::make('expires_on')->label(__('Contract until'))->date(__('formats.date'))->sortable()->placeholder('—')->toggleable(),
                RenewalSchema::statusColumn()->toggleable(),
            ])
            ->filters([
                RenewalSchema::attentionFilter(Network::class),
                RenewalSchema::statusFilter(Network::class),
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
