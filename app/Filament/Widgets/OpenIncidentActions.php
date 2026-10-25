<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\IncidentAction;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class OpenIncidentActions extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Open action items'))
            // Dated actions first, the earliest on top; undated ones last.
            ->query(fn (): Builder => IncidentAction::query()->open()->with('incident')->orderByRaw('due_on IS NULL')->orderBy('due_on'))
            ->emptyStateHeading(__('No open action items'))
            ->columns([
                TextColumn::make('description')->label(__('Action'))->wrap(),
                TextColumn::make('incident.title')->label(__('Incident'))->wrap(),
                TextColumn::make('due_on')
                    ->label(__('Due'))
                    ->date(__('formats.date'))
                    ->color(fn (IncidentAction $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (IncidentAction $record): string => IncidentResource::getUrl('view', ['record' => $record->incident_id]))
            ->recordActions([
                Action::make('complete')
                    ->label(__('Complete'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->action(fn (IncidentAction $record) => $record->update(['done_at' => now()])),
            ]);
    }
}
