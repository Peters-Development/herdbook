<?php

namespace App\Filament\Resources\CredentialReferences\Tables;

use App\Enums\CredentialKind;
use App\Models\CredentialReference;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CredentialReferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->columns([
                TextColumn::make('label')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('kind')->label(__('Type'))->badge(),
                TextColumn::make('username')->label(__('Username'))->searchable()->copyable()->placeholder('—'),
                TextColumn::make('host')->label(__('Host'))->fontFamily(FontFamily::Mono)->searchable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('Type'))->options(CredentialKind::class),
            ])
            ->recordActions([
                Action::make('openVault')
                    ->label('Vaultwarden')
                    ->icon(Heroicon::OutlinedKey)
                    ->url(fn (CredentialReference $record): ?string => $record->vaultUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (CredentialReference $record): bool => $record->vaultUrl() !== null),
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
