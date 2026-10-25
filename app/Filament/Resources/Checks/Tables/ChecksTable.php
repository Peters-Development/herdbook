<?php

namespace App\Filament\Resources\Checks\Tables;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Filament\Support\CheckActions;
use App\Models\Check;
use App\Models\Project;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChecksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('30s')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['hostname.environment.project', 'server']))
            // Down first, then doubt, then the rest.
            ->defaultSort(fn (Builder $query): Builder => self::worstFirst($query))
            ->columns([
                ...self::columns(),
                TextColumn::make('subject')
                    ->label(__('Belongs to'))
                    ->state(fn (Check $record): ?string => $record->hostname?->environment?->full_name ?? $record->server?->name)
                    ->description(fn (Check $record): ?string => $record->hostname?->fqdn)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('project')
                    ->label(__('Project'))
                    ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->where(fn (Builder $query) => $query
                        ->whereHas('hostname.environment', fn (Builder $query) => $query->where('project_id', $data['value']))
                        ->orWhereHas('server.environments', fn (Builder $query) => $query->where('project_id', $data['value']))))
                    ->searchable(),
                SelectFilter::make('state')->label(__('Status'))->options(CheckState::class),
                SelectFilter::make('type')->label(__('Type'))->options(CheckType::class),
                SelectFilter::make('alert_level')->label(__('Alert'))->options(AlertLevel::class),
                TernaryFilter::make('enabled')->label(__('Active'))->placeholder(__('All'))->trueLabel(__('Active'))->falseLabel(__('Paused')),
            ])
            ->recordActions([
                CheckActions::runNow(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    CheckActions::pauseBulk(),
                    CheckActions::resumeBulk(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Shared with the checks of a hostname or server and the dashboard.
     *
     * @return list<TextColumn>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('state')
                ->label(__('Status'))
                ->badge()
                ->state(fn (Check $record): string => match (true) {
                    ! $record->enabled => __('Paused'),
                    $record->isStale() => __('No recent data'),
                    default => $record->state->getLabel(),
                })
                ->color(fn (Check $record): string => match (true) {
                    ! $record->enabled => 'gray',
                    $record->isStale() => 'warning',
                    default => $record->state->getColor(),
                }),
            TextColumn::make('name')
                ->label(__('Check'))
                ->searchable(['name', 'target'])
                ->sortable()
                ->description(fn (Check $record): string => $record->target),
            TextColumn::make('last_checked_at')->label(__('Last checked'))->since()->sortable()->placeholder(__('Not yet')),
            TextColumn::make('last_response_ms')->label(__('Response time'))->suffix(' ms')->placeholder('—')->toggleable(),
            TextColumn::make('last_error')->label(__('Error'))->limit(60)->wrap()->color('danger')->placeholder('—'),
        ];
    }

    public static function worstFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case when enabled = 0 then 4 when state = 'down' then 0 when state = 'pending' then 1 when state = 'unknown' then 2 else 3 end")
            ->orderBy('name');
    }
}
