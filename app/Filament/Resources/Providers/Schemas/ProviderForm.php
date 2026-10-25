<?php

namespace App\Filament\Resources\Providers\Schemas;

use App\Enums\ProviderKind;
use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                            ->required(),
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
                            ->helperText(__('Applies to the domains and contracts with this provider, unless they set their own.')),
                        Textarea::make('notes')->label(__('Notes'))->rows(3)->maxLength(10000)->rules([new NoSecretMaterial])->columnSpanFull(),
                    ]),
            ]);
    }
}
