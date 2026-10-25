<?php

namespace App\Filament\RelationManagers;

use App\Filament\RelationManagers\Concerns\CreatesLinkedRecords;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\Incident;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Incidents linked to an inventory item (Linkable).
 */
class IncidentsRelationManager extends RelationManager
{
    use CreatesLinkedRecords;

    protected static string $relationship = 'incidents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Incidents');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('incident'))
            ->pluralModelLabel(__('incidents'))
            ->recordTitleAttribute('title')
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                TextColumn::make('occurred_on')->label(__('Date'))->date(__('formats.date'))->sortable(),
                TextColumn::make('title')->label(__('Title'))->searchable()->wrap(),
                TextColumn::make('severity')->label(__('Severity'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->headerActions([
                AttachAction::make()->label(__('Link'))->preloadRecordSelect()->recordSelectSearchColumns(['title']),
                $this->createLinkedAction(IncidentResource::class, __('New incident')),
            ])
            ->recordUrl(fn (Incident $record): string => IncidentResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                DetachAction::make()->label(__('Unlink')),
            ]);
    }
}
