<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('archive')
                ->label(__('Archive'))
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->visible(fn (Client $record): bool => $record->archived_at === null)
                ->requiresConfirmation()
                ->action(fn (Client $record) => $record->update(['archived_at' => now()])),
            Action::make('restore')
                ->label(__('Unarchive'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn (Client $record): bool => $record->archived_at !== null)
                ->action(fn (Client $record) => $record->update(['archived_at' => null])),
        ];
    }
}
