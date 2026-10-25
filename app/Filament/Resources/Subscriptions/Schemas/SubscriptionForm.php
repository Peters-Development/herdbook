<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Enums\CostPeriod;
use App\Enums\ProviderKind;
use App\Enums\RenewalPeriod;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Filament\Support\RequestedRecord;
use App\Models\Client;
use App\Models\Project;
use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionForm
{
    /**
     * "?project=12" or "?client=3" on the create page ("Add subscription" on
     * a project or client) fills it in.
     */
    public const string PROJECT_PARAMETER = 'project';

    public const string CLIENT_PARAMETER = 'client';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Subscription'))
                    ->description(__('A license, mail hosting, a SaaS plan or storage: what you pay for besides servers and domains.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder('JetBrains All Products')
                            ->required()
                            ->maxLength(255),
                        Select::make('provider_id')
                            ->label(__('Provider'))
                            ->relationship('provider', 'name')
                            ->searchable()
                            ->preload()
                            // A SaaS vendor is often new: no detour through Settings.
                            ->createOptionForm([
                                TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->unique('providers', 'name'),
                                CheckboxList::make('kinds')->label(__('Supplies'))->options(ProviderKind::class)->columns(3)->default([ProviderKind::Other->value])->required(),
                            ]),
                        TextInput::make('plan')->label(__('Plan'))->maxLength(255),
                        Select::make('project_id')
                            ->label(__('For project'))
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => RequestedRecord::id(self::PROJECT_PARAMETER, Project::class))
                            ->placeholder(__('No project')),
                        Select::make('client_id')
                            ->label(__('For client'))
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => RequestedRecord::id(self::CLIENT_PARAMETER, Client::class))
                            ->placeholder(__('From the project, or own')),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(3)->maxLength(10000)
                            ->rules([new NoSecretMaterial])
                            ->columnSpanFull(),
                    ]),
                CostSchema::formSection(CostPeriod::Month),
                RenewalSchema::formSection(description: __('If a monthly subscription simply keeps running, leave the date empty.'), term: RenewalPeriod::OneMonth->value),
            ]);
    }
}
