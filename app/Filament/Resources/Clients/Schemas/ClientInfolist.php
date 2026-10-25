<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Support\CostSchema;
use App\Models\Client;
use App\Models\Project;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('external_url')
                            ->label(__('External link'))
                            ->state(fn (Client $record): ?string => $record->external_url ? __('Open link') : null)
                            ->url(fn (Client $record): ?string => $record->external_url, shouldOpenInNewTab: true)
                            ->placeholder('—'),
                        TextEntry::make('archived_at')
                            ->label(__('Status'))
                            ->state(fn (Client $record): string => $record->archived_at ? __('Archived') : __('Active'))
                            ->badge()
                            ->color(fn (Client $record): string => $record->archived_at ? 'gray' : 'success'),
                        TextEntry::make('projects')
                            ->label(__('Projects'))
                            ->state(fn (Client $record): array => $record->projects->map(fn (Project $project): string => $project->name)->all())
                            ->badge()
                            ->placeholder('—'),
                    ]),
                Section::make(__('Contact during outages'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('contact_name')->label(__('Name'))->placeholder('—'),
                        TextEntry::make('contact_phone')
                            ->label(__('Phone'))
                            ->url(fn (?string $state): ?string => $state ? 'tel:'.preg_replace('/[^0-9+]/', '', $state) : null)
                            ->placeholder('—'),
                        TextEntry::make('contact_email')
                            ->label(__('Email'))
                            ->url(fn (?string $state): ?string => $state ? "mailto:{$state}" : null)
                            ->placeholder('—'),
                    ]),
                CostSchema::ownerSection(),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—'),
            ]);
    }
}
