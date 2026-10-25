<?php

namespace App\Filament\Widgets;

use App\Enums\CheckState;
use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Checks\Tables\ChecksTable;
use App\Filament\Support\CheckActions;
use App\Models\Check;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Below the band and the tiles: the checks behind them that are down or in
 * doubt, with "Check now".
 */
class FailingChecks extends TableWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Checks that need attention'))
            ->query(fn (): Builder => Check::query()
                ->where('enabled', true)
                ->where(fn (Builder $query) => $query
                    ->whereIn('state', [CheckState::Down, CheckState::Pending])
                    ->orWhereKey($this->staleIds())))
            ->defaultSort(fn (Builder $query): Builder => ChecksTable::worstFirst($query))
            ->poll('30s')
            ->paginated(false)
            ->emptyStateHeading(__('Everything is up and being checked'))
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns(ChecksTable::columns())
            ->recordUrl(fn (Check $record): string => CheckResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                CheckActions::runNow(),
            ]);
    }

    /**
     * Checks without a recent result: computed per check (its interval), on
     * a few hundred rows at most.
     *
     * @return list<int>
     */
    private function staleIds(): array
    {
        return Check::query()
            ->where('enabled', true)
            ->get(['id', 'enabled', 'state', 'interval_seconds', 'last_checked_at', 'created_at'])
            ->filter->isStale()
            ->modelKeys();
    }
}
