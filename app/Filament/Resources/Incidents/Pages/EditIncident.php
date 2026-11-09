<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Filament\Resources\Concerns\GuardsConcurrentEdits;
use App\Filament\Resources\Incidents\IncidentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditIncident extends EditRecord
{
    use GuardsConcurrentEdits;

    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Monitoring moves an incident between open and watching, and adds the
     * servers and projects of a check that joins it; agents change it too.
     */
    protected function changedMeanwhile(): void
    {
        Notification::make()
            ->warning()
            ->title(__('This incident was changed meanwhile.'))
            ->body(__('Reload the page to see the change; yours was not saved.'))
            ->persistent()
            ->send();
    }
}
