<?php

namespace App\Filament\Resources\Domains\Schemas;

use App\Enums\CostPeriod;
use App\Enums\ProviderKind;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DomainForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Domain'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Domain name'))
                            ->placeholder(__('example.com'))
                            ->required()
                            ->maxLength(255)
                            // A local zone may be a single label ("internal", "lan").
                            ->regex(fn (Get $get): string => $get('local_only') ? '/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)*$/i' : '/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i')
                            ->validationMessages(['regex' => __('Enter just the domain name, such as example.com.')])
                            ->unique(ignoreRecord: true),
                        Select::make('client_id')
                            ->label(__('Belongs to client'))
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder(__('Own domain')),
                        Select::make('registrar_id')
                            ->label(__('Registrar'))
                            ->relationship('registrar', 'name', fn (Builder $query, ?Model $record) => $query->offering(ProviderKind::Registrar, $record?->registrar_id))
                            ->searchable()
                            ->preload()
                            ->hidden(fn (Get $get): bool => (bool) $get('local_only')),
                        Select::make('dns_provider_id')->validationAttribute(__('DNS host'))
                            ->label(__('DNS host'))
                            ->relationship('dnsProvider', 'name', fn (Builder $query, ?Model $record) => $query->offering(ProviderKind::Dns, $record?->dns_provider_id))
                            ->searchable()
                            ->preload(),
                        Toggle::make('local_only')
                            ->label(__('Only on the local network'))
                            ->helperText(__('Registered nowhere: a domain in your local DNS only, such as home.arpa. It has no registrar, costs or renewal (they are cleared), and every name under it is local, until you turn this off again.'))
                            ->live()
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(3)->maxLength(10000)
                            ->rules([new NoSecretMaterial])
                            ->columnSpanFull(),
                    ]),
                // A local domain is registered nowhere (Domain::hasContract()).
                CostSchema::formSection(CostPeriod::Year)->hidden(fn (Get $get): bool => (bool) $get('local_only')),
                RenewalSchema::formSection()->hidden(fn (Get $get): bool => (bool) $get('local_only')),
            ]);
    }
}
