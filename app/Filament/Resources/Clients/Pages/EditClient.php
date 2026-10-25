<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // Deleting would silently turn its projects, domains and subscriptions into "own"
            // ones; archiving keeps them right.
            DeleteAction::make()
                ->before(function (DeleteAction $action, Client $record): void {
                    if ($record->projects()->exists() || $record->domains()->exists() || $record->subscriptions()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title(__('This client still has projects, domains or subscriptions'))
                            ->body(__('Archive the client, or unlink its projects, domains and subscriptions first.'))
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
