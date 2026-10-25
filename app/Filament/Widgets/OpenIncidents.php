<?php

namespace App\Filament\Widgets;

use App\Enums\IncidentStatus;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\Incident;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class OpenIncidents extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Ongoing incidents'))
            ->query(fn (): Builder => Incident::query()->where('status', '!=', IncidentStatus::Resolved))
            ->defaultSort('occurred_on', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading(__('No ongoing incidents'))
            ->columns([
                TextColumn::make('occurred_on')->label(__('Date'))->date(__('formats.date')),
                TextColumn::make('title')->label(__('Incident'))->wrap(),
                TextColumn::make('severity')->label(__('Severity'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordUrl(fn (Incident $record): string => IncidentResource::getUrl('view', ['record' => $record]));
    }
}
