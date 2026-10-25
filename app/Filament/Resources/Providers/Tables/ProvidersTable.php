<?php

namespace App\Filament\Resources\Providers\Tables;

use App\Enums\ProviderKind;
use App\Models\Provider;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Provider'))->searchable()->sortable(),
                TextColumn::make('kinds')
                    ->label(__('Supplies'))
                    ->state(fn (Provider $record): array => array_map(
                        fn (string $kind): string => ProviderKind::from($kind)->getLabel(),
                        $record->kinds ?? [],
                    ))
                    ->badge(),
                TextColumn::make('servers_count')->label(__('Servers'))->counts('servers'),
                TextColumn::make('registered_domains_count')->label(__('Domains'))->counts('registeredDomains'),
                TextColumn::make('panel_url')
                    ->label(__('Control panel'))
                    ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                    ->limit(40)
                    ->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
