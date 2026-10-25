<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Deliberately small: invoicing and customer management live in another tool.
 */
class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Client'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                        TextInput::make('external_url')
                            ->label(__('External link'))
                            ->url()
                            ->maxLength(255)
                            ->helperText(__('Where to find this client in your invoicing or CRM tool.')),
                    ]),
                Section::make(__('Contact during outages'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('contact_name')->label(__('Name'))->maxLength(255),
                        TextInput::make('contact_phone')->label(__('Phone'))->tel()->maxLength(50),
                        TextInput::make('contact_email')->label(__('Email'))->email()->maxLength(255),
                    ]),
                Textarea::make('notes')->label(__('Notes'))->rows(3)->maxLength(10000)->rules([new NoSecretMaterial]),
            ]);
    }
}
