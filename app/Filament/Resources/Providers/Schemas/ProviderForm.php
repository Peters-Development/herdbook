<?php

namespace App\Filament\Resources\Providers\Schemas;

use App\Enums\ProviderKind;
use App\Models\DomainPrice;
use App\Models\Provider;
use App\Rules\DistinctItems;
use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Provider'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->unique(ignoreRecord: true),
                        CheckboxList::make('kinds')
                            ->label(__('Supplies'))
                            ->options(ProviderKind::class)
                            ->columns(3)
                            ->required()
                            // A registrar has a price list.
                            ->live(),
                        TextInput::make('panel_url')->label(__('Control panel'))->url()->maxLength(255),
                        TextInput::make('website_url')->label(__('Website'))->url()->maxLength(255),
                        TextInput::make('support_email')->label(__('Support email'))->email()->maxLength(255),
                        TextInput::make('support_phone')->label(__('Support phone'))->tel()->maxLength(50),
                        TextInput::make('renewal_lead_days')
                            ->label(__('Renewal lead time (days)'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->placeholder((string) config('herdbook.renewal.default_lead_days'))
                            ->explain(__('Applies to the domains and contracts with this provider, unless they set their own.')),
                        Textarea::make('notes')->label(__('Notes'))->rows(3)->maxLength(10000)->rules([new NoSecretMaterial])->columnSpanFull(),
                    ]),
                self::priceListSection(),
            ]);
    }

    /**
     * A price of the list, per year: also in the domain form's "Add to the
     * price list".
     */
    public static function priceInput(): TextInput
    {
        return TextInput::make('cost_amount')
            ->label(__('Price per year'))
            ->validationAttribute(__('Price per year'))
            ->numeric()
            ->minValue(0)
            ->maxValue(99999999.99)
            ->prefix(config('herdbook.currency_symbol'))
            ->required();
    }

    /**
     * What a domain costs per extension (Domain::costAmount()). Also when it
     * no longer is a registrar but still has prices or domains: hidden, they
     * would not be saved, yet still count for its domains (and the costs page
     * links here for a missing price).
     */
    private static function priceListSection(): Section
    {
        return Section::make(__('Price list'))
            ->explain(__('What a domain costs per extension per year, excluding VAT. Applies to its domains without an amount of their own, so a price change is made here once.'))
            ->visible(fn (Get $get, ?Provider $record): bool => collect($get('kinds'))->contains(fn (ProviderKind|string $kind): bool => $kind === ProviderKind::Registrar || $kind === ProviderKind::Registrar->value)
                || (bool) $record?->domainPrices()->exists()
                || (bool) $record?->registeredDomains()->exists())
            ->schema([
                Repeater::make('domainPrices')
                    ->label(__('Price list'))
                    ->hiddenLabel()
                    ->relationship(modifyQueryUsing: fn (Builder $query) => $query->orderBy('extension'))
                    ->table([
                        TableColumn::make(__('Extension'))->markAsRequired(),
                        TableColumn::make(__('Price per year'))->markAsRequired(),
                    ])
                    ->schema([
                        TextInput::make('extension')
                            ->label(__('Extension'))
                            ->validationAttribute(__('Extension'))
                            ->placeholder('com')
                            ->prefix('.')
                            ->required()
                            ->maxLength(63)
                            ->regex('/^\.?(?!-)[a-z0-9-]+(\.(?!-)[a-z0-9-]+)*\.?$/i')
                            ->validationMessages(['regex' => __('Enter an extension such as com, nl or co.uk.')])
                            ->extraInputAttributes(['class' => 'fi-font-mono']),
                        self::priceInput(),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel(__('Add extension'))
                    ->rules([new DistinctItems(
                        ['extension'],
                        __('The same extension is in the list twice.'),
                        // As stored: ".COM" is "com".
                        fn (mixed $extension): mixed => is_string($extension) ? DomainPrice::normalize($extension) : $extension,
                    )]),
            ]);
    }
}
