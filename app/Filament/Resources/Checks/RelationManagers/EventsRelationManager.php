<?php

namespace App\Filament\Resources\Checks\RelationManagers;

use App\Enums\AlertLevel;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\CheckEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * When the check went down and came back.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Status changes');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('status change'))
            ->pluralModelLabel(__('status changes'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label(__('When'))->dateTime(__('formats.datetime')),
                TextColumn::make('to_state')->label(__('Changed to'))->badge(),
                TextColumn::make('error')->label(__('Error'))->wrap()->placeholder('—'),
                TextColumn::make('incident.title')
                    ->label(__('Incident'))
                    ->url(fn (CheckEvent $record): ?string => $record->incident_id ? IncidentResource::getUrl('view', ['record' => $record->incident_id]) : null)
                    ->placeholder('—'),
                TextColumn::make('notified_at')
                    ->label(__('Alert sent'))
                    ->state(fn (CheckEvent $record): ?string => match (true) {
                        $record->notify_failed => __('Failed'),
                        $record->notified_at === null => null,
                        default => $record->notified_at->diffForHumans(),
                    })
                    ->color(fn (CheckEvent $record): ?string => $record->notify_failed ? 'danger' : null)
                    ->placeholder($this->getOwnerRecord()->alert_level === AlertLevel::Silent ? '—' : __('Not yet')),
            ]);
    }
}
