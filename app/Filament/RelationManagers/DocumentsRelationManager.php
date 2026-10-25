<?php

namespace App\Filament\RelationManagers;

use App\Filament\RelationManagers\Concerns\CreatesLinkedRecords;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Runbooks, manuals and notes linked to an inventory item (Linkable).
 */
class DocumentsRelationManager extends RelationManager
{
    use CreatesLinkedRecords;

    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Documents');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('document'))
            ->pluralModelLabel(__('documents'))
            ->recordTitleAttribute('title')
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label(__('Title'))->searchable()->wrap(),
                TextColumn::make('kind')->label(__('Type'))->badge(),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->headerActions([
                AttachAction::make()->label(__('Link'))->preloadRecordSelect()->recordSelectSearchColumns(['title']),
                $this->createLinkedAction(DocumentResource::class, __('New document')),
            ])
            ->recordUrl(fn (Document $record): string => DocumentResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                DetachAction::make()->label(__('Unlink')),
            ]);
    }
}
