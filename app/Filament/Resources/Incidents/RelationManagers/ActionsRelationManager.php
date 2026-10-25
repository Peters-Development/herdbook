<?php

namespace App\Filament\Resources\Incidents\RelationManagers;

use App\Models\IncidentAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Follow-ups: what has to change so this does not happen again.
 */
class ActionsRelationManager extends RelationManager
{
    protected static string $relationship = 'actions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Action items');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('description')->label(__('Action'))->required()->maxLength(255)->columnSpanFull(),
                DatePicker::make('due_on')->label(__('Due'))->native(false)->displayFormat(__('formats.date')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('action item'))
            ->pluralModelLabel(__('action items'))
            ->defaultSort('done_at')
            ->columns([
                TextColumn::make('description')->label(__('Action'))->wrap(),
                TextColumn::make('due_on')->label(__('Due'))->date(__('formats.date'))->placeholder('—'),
                TextColumn::make('done_at')->label(__('Completed'))->dateTime(__('formats.date'))->placeholder(__('Open')),
            ])
            ->filters([
                TernaryFilter::make('open')
                    ->label(__('Status'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Open'))
                    ->falseLabel(__('Completed'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('done_at'),
                        false: fn (Builder $query) => $query->whereNotNull('done_at'),
                    ),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label(__('Complete'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (IncidentAction $record): bool => ! $record->isDone())
                    ->action(fn (IncidentAction $record) => $record->update(['done_at' => now()])),
                Action::make('reopen')
                    ->label(__('Reopen'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (IncidentAction $record): bool => $record->isDone())
                    ->action(fn (IncidentAction $record) => $record->update(['done_at' => null])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
