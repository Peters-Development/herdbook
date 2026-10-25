<?php

namespace App\Filament\Resources\Networks\Schemas;

use App\Enums\CostPeriod;
use App\Enums\ProviderKind;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Network;
use App\Models\NetworkAddress;
use App\Rules\DistinctItems;
use App\Rules\NoSecretMaterial;
use App\Rules\Subnet;
use Closure;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NetworkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Network'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('Office'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('location')->label(__('Location'))->maxLength(255),
                        Select::make('provider_id')
                            ->label(__('Internet provider'))
                            ->relationship('provider', 'name', fn (Builder $query, ?Model $record) => $query->offering(ProviderKind::Internet, $record?->provider_id))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText(__('Who you call when the line is down: a provider that supplies :kind.', ['kind' => ProviderKind::Internet->getLabel()])),
                    ]),
                CostSchema::formSection(CostPeriod::Month),
                // A LAN without a line of its own has no contract (Network::hasContract()). The
                // stored record counts too: emptying the date must still be saved.
                RenewalSchema::formSection(__('Contract'))
                    ->visible(fn (Get $get, ?Network $record): bool => filled($get('provider_id')) || filled($get('expires_on')) || (bool) $record?->hasContract()),
                Section::make(__('Addressing'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('subnet')
                            ->label(__('Subnet'))
                            ->placeholder('192.168.1.0/24')
                            ->maxLength(49)
                            ->rules([new Subnet])
                            ->extraInputAttributes(['class' => 'fi-font-mono']),
                        TextInput::make('gateway')
                            ->label(__('Gateway'))
                            ->placeholder('192.168.1.1')
                            ->ip()
                            ->extraInputAttributes(['class' => 'fi-font-mono']),
                        TextInput::make('public_ip')
                            ->label(__('Static IP address'))
                            ->helperText(__('The public address of the line, if it has a static IP.'))
                            ->ip()
                            ->extraInputAttributes(['class' => 'fi-font-mono']),
                    ]),
                Section::make(__('Addresses'))
                    ->description(__('Other devices in this network, such as the router, a NAS or a printer. You put a server in the network from its own page; if it has the same address as a device, the network\'s page says so.'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('addresses')
                            ->label(__('Addresses'))
                            ->hiddenLabel()
                            ->relationship()
                            ->table([
                                TableColumn::make(__('IP address'))->markAsRequired(),
                                TableColumn::make(__('Device'))->markAsRequired(),
                                TableColumn::make(__('MAC address')),
                                TableColumn::make(__('Note')),
                            ])
                            ->schema([
                                TextInput::make('address')
                                    ->label(__('IP address'))
                                    ->validationAttribute(__('IP address'))
                                    ->ip()
                                    ->required()
                                    ->extraInputAttributes(['class' => 'fi-font-mono']),
                                TextInput::make('name')->label(__('Device'))->required()->maxLength(255),
                                TextInput::make('mac_address')
                                    ->label(__('MAC address'))
                                    ->validationAttribute(__('MAC address'))
                                    ->maxLength(17)
                                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                        if (filled($value) && NetworkAddress::normalizeMac((string) $value) === null) {
                                            $fail(__('Enter a MAC address such as 02:00:5e:12:34:56.'));
                                        }
                                    }])
                                    ->extraInputAttributes(['class' => 'fi-font-mono']),
                                TextInput::make('notes')->label(__('Note'))->maxLength(255),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel(__('Add address'))
                            ->rules([new DistinctItems(
                                ['address'],
                                __('The same IP address is in the list twice.'),
                                // As stored: "fd00:0::1" is "fd00::1".
                                fn (mixed $address): mixed => is_string($address) && filled($address) ? NetworkAddress::sortKey(trim($address)) : $address,
                            )]),
                    ]),
                Section::make(__('Notes'))
                    ->description(__('Passwords (Wi-Fi, router, controller) belong in Vaultwarden: link a credential to this network.'))
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        MarkdownEditor::make('notes')->label(__('Notes'))->hiddenLabel()->rules([new NoSecretMaterial]),
                    ]),
            ]);
    }
}
