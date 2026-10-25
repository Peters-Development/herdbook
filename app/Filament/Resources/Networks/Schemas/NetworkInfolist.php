<?php

namespace App\Filament\Resources\Networks\Schemas;

use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Support\CostSchema;
use App\Filament\Support\ImpactSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Network;
use App\Services\Impact\ImpactAnalyzer;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class NetworkInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Network'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('subnet')->label(__('Subnet'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                        TextEntry::make('gateway')->label(__('Gateway'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                        TextEntry::make('public_ip')->label(__('Static IP address'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                        TextEntry::make('location')->label(__('Location'))->placeholder('—'),
                        TextEntry::make('provider.name')
                            ->label(__('Internet provider'))
                            ->url(fn (Network $record): ?string => $record->provider_id ? ProviderResource::getUrl('view', ['record' => $record->provider_id]) : null)
                            ->placeholder('—'),
                        CostSchema::entry(),
                    ]),
                RenewalSchema::infolistSection(__('Contract'))->columnSpanFull(),
                Section::make(__('Addresses'))
                    ->description(__('The servers in this network and the other devices, sorted by address.'))
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('address_book')
                            ->label(__('Addresses'))
                            ->hiddenLabel()
                            ->state(fn (Network $record): array => $record->addressBook()
                                ->map(fn (array $entry): array => [
                                    'address' => $entry['address'],
                                    'name' => $entry['name'],
                                    'type' => $entry['server'] ? __('Server') : __('Device'),
                                    'url' => $entry['server'] ? ServerResource::getUrl('view', ['record' => $entry['server']]) : null,
                                    'mac_address' => $entry['mac_address'],
                                    'notes' => implode(' · ', array_filter([
                                        $entry['duplicate'] ? __('Duplicate address') : null,
                                        $entry['outside'] ? __('Outside :subnet', ['subnet' => $record->subnet]) : null,
                                        $entry['server'] && $entry['address'] === null ? __('No private IP') : null,
                                        $entry['notes'],
                                    ])) ?: null,
                                ])
                                ->all())
                            ->table([
                                TableColumn::make(__('IP address')),
                                TableColumn::make(__('Name')),
                                TableColumn::make(__('Type')),
                                TableColumn::make(__('MAC address')),
                                TableColumn::make(__('Note')),
                            ])
                            ->schema([
                                TextEntry::make('address')->label(__('IP address'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                                // The row's own data: Get does not reach into an array item.
                                TextEntry::make('name')->label(__('Name'))->url(fn (TextEntry $component): ?string => data_get($component->getContainer()->getConstantState(), 'url')),
                                TextEntry::make('type')->label(__('Type')),
                                TextEntry::make('mac_address')->label(__('MAC address'))->fontFamily(FontFamily::Mono)->copyable()->placeholder('—'),
                                TextEntry::make('notes')->label(__('Notes'))->placeholder('—'),
                            ])
                            ->placeholder(__('No servers or addresses yet. Add a server to this network from the server\'s page.')),
                    ]),
                ImpactSchema::section(
                    __('What goes down when this network fails (the line or the power), including through servers that depend on it.'),
                    fn (Network $record) => app(ImpactAnalyzer::class)->forServers($record->servers()->pluck('id')->all()),
                ),
                Section::make(__('Notes'))
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('notes')->label(__('Notes'))->hiddenLabel()->markdown()->placeholder(__('No notes.')),
                    ]),
            ]);
    }
}
