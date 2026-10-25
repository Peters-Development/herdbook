<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\CostSchema;
use App\Models\Project;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('type')->label(__('Type'))->badge(),
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('client.name')
                            ->label(__('Client'))
                            ->placeholder(__('Own project'))
                            ->url(fn (Project $record): ?string => $record->client
                                ? ClientResource::getUrl('view', ['record' => $record->client])
                                : null),
                        TextEntry::make('client.contact_name')
                            ->label(__('Outage contact'))
                            ->state(fn (Project $record): ?string => $record->client
                                ? (implode(' · ', array_filter([$record->client->contact_name, $record->client->contact_phone, $record->client->contact_email])) ?: null)
                                : null)
                            ->placeholder('—')
                            ->visible(fn (Project $record): bool => $record->client !== null),
                        TextEntry::make('repository_url')
                            ->label(__('Repository'))
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->placeholder('—'),
                        IconEntry::make('on_status_screen')
                            ->label(__('On the status screen'))
                            ->boolean(),
                        TextEntry::make('unmonitored')
                            ->label(__('Monitoring'))
                            ->state(__('Not needed'))
                            ->visible(fn (Project $record): bool => $record->unmonitored),
                        TextEntry::make('tags')
                            ->label(__('Stack'))
                            ->badge()
                            ->placeholder('—')
                            ->columnSpan(2),
                        TextEntry::make('description')
                            ->label(__('Description'))
                            ->placeholder('—')
                            ->columnSpan(2),
                    ]),
                CostSchema::ownerSection(),
                Section::make(__('Notes'))
                    ->collapsible()
                    ->schema([
                        TextEntry::make('notes')
                            ->label(__('Notes'))
                            ->hiddenLabel()
                            ->markdown()
                            ->placeholder(__('No notes.')),
                    ]),
            ]);
    }
}
