<?php

namespace App\Filament\RelationManagers;

use App\Filament\RelationManagers\Concerns\CreatesLinkedRecords;
use App\Filament\Resources\CredentialReferences\CredentialReferenceResource;
use App\Models\CredentialReference;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the logins for an inventory item live (Linkable).
 */
class CredentialReferencesRelationManager extends RelationManager
{
    use CreatesLinkedRecords;

    protected static string $relationship = 'credentialReferences';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Credentials');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('credential'))
            ->pluralModelLabel(__('credentials'))
            ->recordTitleAttribute('label')
            ->defaultSort('label')
            ->columns([
                TextColumn::make('label')->label(__('Name'))->searchable(),
                TextColumn::make('kind')->label(__('Type'))->badge(),
                TextColumn::make('username')->label(__('Username'))->copyable()->placeholder('—'),
                TextColumn::make('host')->label(__('Host'))->fontFamily(FontFamily::Mono)->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()->label(__('Link'))->preloadRecordSelect()->recordSelectSearchColumns(['label', 'username', 'host']),
                $this->createLinkedAction(CredentialReferenceResource::class, __('New credential')),
            ])
            ->recordUrl(fn (CredentialReference $record): string => CredentialReferenceResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('openVault')
                    ->label('Vaultwarden')
                    ->icon(Heroicon::OutlinedKey)
                    ->url(fn (CredentialReference $record): ?string => $record->vaultUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (CredentialReference $record): bool => $record->vaultUrl() !== null),
                DetachAction::make()->label(__('Unlink')),
            ]);
    }
}
