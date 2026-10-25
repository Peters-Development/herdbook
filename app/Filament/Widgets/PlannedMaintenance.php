<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Support\MaintenanceSchema;
use App\Models\Maintenance;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Planned maintenance, by when it is due (the end of its window, or else its
 * start), so what is overdue is on top. Not shown while nothing is planned.
 */
class PlannedMaintenance extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Maintenance::query()->planned()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Planned maintenance'))
            ->query(fn (): Builder => Maintenance::query()->planned()->with(['servers', 'projects'])->orderByDue())
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading(__('Nothing planned'))
            ->columns([
                TextColumn::make('starts_at')->label(__('When'))->dateTime(__('formats.datetime')),
                TextColumn::make('title')
                    ->label(__('Maintenance'))
                    ->wrap()
                    ->description(fn (Maintenance $record): ?string => MaintenanceSchema::linkedTo($record)),
                MaintenanceSchema::tasksColumn(),
                MaintenanceSchema::statusColumn(),
            ])
            ->recordUrl(fn (Maintenance $record): string => MaintenanceResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                MaintenanceSchema::completeAction(),
                MaintenanceSchema::cancelAction(),
            ]);
    }
}
