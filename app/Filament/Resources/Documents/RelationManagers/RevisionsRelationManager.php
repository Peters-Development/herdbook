<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Models\DocumentRevision;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Earlier versions of the body; restoring one keeps the current version as
 * a revision too, so nothing is ever lost.
 */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Earlier versions');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('body')->label(__('Content'))->hiddenLabel()->markdown()->prose(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('version'))
            ->pluralModelLabel(__('versions'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Replaced on'))->dateTime(__('formats.datetime')),
                TextColumn::make('user.name')->label(__('By'))->placeholder('—'),
                TextColumn::make('body')
                    ->label(__('First line'))
                    ->formatStateUsing(fn (string $state): string => Str::limit(strip_tags(Str::markdown($state, ['html_input' => 'strip'])), 80)),
            ])
            ->recordActions([
                ViewAction::make()->label(__('View')),
                Action::make('restore')
                    ->label(__('Restore version'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->requiresConfirmation()
                    ->modalDescription(__('The current content is kept as an earlier version.'))
                    ->action(function (DocumentRevision $record): void {
                        /** @var Document $document */
                        $document = $this->getOwnerRecord();
                        $document->update(['body' => $record->body]);

                        Notification::make()->success()->title(__('Version restored'))->send();

                        // The page around this table still holds the old body (and
                        // the edit form would save it back): reload the document.
                        $this->redirect(DocumentResource::getUrl('view', ['record' => $document]));
                    }),
            ]);
    }
}
