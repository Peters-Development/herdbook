<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\RelationManagers\MaintenancesRelationManager;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Support\RenewalSchema;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

    /**
     * Something changed on the Maintenance tab: a new instance, so the
     * "last done" section reads it again (lastDone() is cached per instance).
     */
    #[On(MaintenancesRelationManager::CHANGED)]
    public function maintenanceChanged(): void
    {
        $this->record = $this->getRecord()->fresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            RenewalSchema::renewAction(),
            EditAction::make(),
            ActionGroup::make([
                RenewalSchema::stopAction(),
                RenewalSchema::resumeAction(),
            ]),
        ];
    }
}
