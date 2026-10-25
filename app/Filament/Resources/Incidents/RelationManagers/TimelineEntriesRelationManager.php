<?php

namespace App\Filament\Resources\Incidents\RelationManagers;

use App\Rules\NoSecretMaterial;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TimelineEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'timelineEntries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Timeline');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                DateTimePicker::make('occurred_at')
                    ->label(__('When'))
                    ->native(false)
                    ->displayFormat(__('formats.datetime'))
                    ->seconds(false)
                    ->default(fn () => now())
                    ->required(),
                Textarea::make('note')->label(__('What'))->rows(3)->maxLength(10000)->required()->rules([new NoSecretMaterial]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('event'))
            ->pluralModelLabel(__('events'))
            ->defaultSort('occurred_at')
            ->paginated(false)
            ->columns([
                TextColumn::make('occurred_at')->label(__('When'))->dateTime(__('formats.datetime')),
                TextColumn::make('note')->label(__('What'))->wrap(),
                TextColumn::make('user.name')->label(__('By'))->placeholder('—'),
            ])
            ->headerActions([
                // Not "create another": Filament casts the default "now" a second time
                // for the next form, which puts it an extra UTC offset ahead.
                CreateAction::make()
                    ->createAnother(false)
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => auth()->id()]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
