<?php

namespace App\Filament\Resources\CredentialReferences\Schemas;

use App\Filament\Support\LinkSchema;
use App\Models\CredentialReference;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class CredentialReferenceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('kind')->label(__('Type'))->badge(),
                        TextEntry::make('username')->label(__('Username'))->copyable()->placeholder('—'),
                        TextEntry::make('host')
                            ->label(__('Host'))
                            ->fontFamily(FontFamily::Mono)
                            ->state(fn (CredentialReference $record): ?string => $record->host === null ? null : $record->host.($record->port ? ":{$record->port}" : ''))
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('vault_item_id')
                            ->label('Vaultwarden')
                            ->state(fn (CredentialReference $record): ?string => match (true) {
                                $record->vaultUrl() !== null => __('Open in Vaultwarden'),
                                filled($record->vault_item_id) => __('Item :id (set HERDBOOK_VAULT_URL for a link)', ['id' => $record->vault_item_id]),
                                default => null,
                            })
                            ->url(fn (CredentialReference $record): ?string => $record->vaultUrl(), shouldOpenInNewTab: true)
                            ->placeholder(__('No item linked'))
                            ->columnSpan(2),
                        TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                    ]),
                LinkSchema::infolistSection(),
            ]);
    }
}
