<?php

namespace App\Filament\Resources\Incidents\Schemas;

use App\Filament\Support\LinkSchema;
use App\Models\Incident;
use Carbon\CarbonInterval;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IncidentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('severity')->label(__('Severity'))->badge(),
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('occurred_on')->label(__('Date'))->date(__('formats.date')),
                        TextEntry::make('source')->label(__('Source'))->badge()->color('gray'),
                        TextEntry::make('started_at')->label(__('Started'))->dateTime(__('formats.datetime'))->placeholder('—'),
                        TextEntry::make('detected_at')->label(__('Detected'))->dateTime(__('formats.datetime'))->placeholder('—'),
                        TextEntry::make('resolved_at')->label(__('Resolved'))->dateTime(__('formats.datetime'))->placeholder('—'),
                        TextEntry::make('durations')
                            ->label(__('Duration'))
                            ->state(fn (Incident $record): ?string => self::describeDurations($record))
                            ->placeholder('—'),
                    ]),
                Section::make(__('Impact and root cause'))
                    ->schema([
                        TextEntry::make('impact')->label(__('Impact'))->placeholder('—'),
                        TextEntry::make('root_cause')->label(__('Root cause'))->markdown()->placeholder('—'),
                    ]),
                Section::make(__('Report'))
                    ->collapsible()
                    ->schema([
                        TextEntry::make('body')->label(__('Report'))->hiddenLabel()->markdown()->prose()->placeholder(__('No report.')),
                    ]),
                LinkSchema::infolistSection(),
            ]);
    }

    private static function describeDurations(Incident $record): ?string
    {
        $parts = array_filter([
            $record->duration() ? __(':duration down', ['duration' => self::human($record->duration())]) : null,
            $record->timeToDetect() ? __(':duration to detect', ['duration' => self::human($record->timeToDetect())]) : null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private static function human(CarbonInterval $interval): string
    {
        return $interval->forHumans(['parts' => 2, 'short' => true]);
    }
}
