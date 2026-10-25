<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Subscription;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('Subscription'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('provider.name')
                            ->label(__('Provider'))
                            ->url(fn (Subscription $record): ?string => $record->provider ? ProviderResource::getUrl('view', ['record' => $record->provider]) : null)
                            ->placeholder('—'),
                        TextEntry::make('plan')->label(__('Plan'))->placeholder('—'),
                        CostSchema::entry(),
                        TextEntry::make('project.name')
                            ->label(__('For project'))
                            ->url(fn (Subscription $record): ?string => $record->project ? ProjectResource::getUrl('view', ['record' => $record->project]) : null)
                            ->placeholder(__('No project')),
                        TextEntry::make('for_client')
                            ->label(__('For client'))
                            ->state(fn (Subscription $record): ?string => $record->effectiveClient()?->name)
                            ->url(fn (Subscription $record): ?string => ($client = $record->effectiveClient()) ? ClientResource::getUrl('view', ['record' => $client]) : null)
                            ->placeholder(__('Own')),
                        TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                    ]),
                RenewalSchema::infolistSection(),
            ]);
    }
}
