<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Filament\Resources\Concerns\GuardsConcurrentEdits;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Support\ChangedMeanwhile;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
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
        ChangedMeanwhile::notify(__('This incident was changed meanwhile.'));
    }
}
