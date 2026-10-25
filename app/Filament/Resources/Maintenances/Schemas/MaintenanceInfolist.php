<?php

namespace App\Filament\Resources\Maintenances\Schemas;

use App\Enums\MaintenanceStatus;
use App\Filament\Support\LinkSchema;
use App\Filament\Support\MaintenanceSchema;
use App\Models\Maintenance;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MaintenanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(5)
                    ->schema([
                        MaintenanceSchema::statusEntry(),
                        MaintenanceSchema::tasksEntry(),
                        TextEntry::make('starts_at')->label(__('Start'))->dateTime(__('formats.datetime')),
                        TextEntry::make('ends_at')->label(__('End'))->dateTime(__('formats.datetime'))->placeholder('—'),
                        TextEntry::make('user.name')->label(__('By'))->placeholder('—'),
                    ]),
                // A quick entry has no plan; a planned one shows that it has none yet.
                Section::make(__('Work plan'))
                    ->collapsible()
                    ->visible(fn (Maintenance $record): bool => filled($record->plan) || $record->status === MaintenanceStatus::Planned)
                    ->schema([
                        TextEntry::make('plan')->label(__('Work plan'))->hiddenLabel()->markdown()->prose()->placeholder(__('No plan yet.')),
                    ]),
                Section::make(__('What was done'))
                    ->collapsible()
                    ->visible(fn (Maintenance $record): bool => filled($record->report) || $record->status !== MaintenanceStatus::Planned)
                    ->schema([
                        TextEntry::make('report')->label(__('What was done'))->hiddenLabel()->markdown()->prose()->placeholder(__('Nothing entered.')),
                    ]),
                LinkSchema::infolistSection(['projects', 'servers']),
            ]);
    }
}
