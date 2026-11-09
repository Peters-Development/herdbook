<?php

namespace App\Filament\Resources\Maintenances\Pages;

use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Support\MaintenanceSchema;
use App\Models\Maintenance;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Locked;

class EditMaintenance extends EditRecord
{
    protected static string $resource = MaintenanceResource::class;

    /**
     * Maintenance::version() as the form was filled: saving over a change
     * made since (an agent completed it, another tab) would undo it.
     */
    #[Locked]
    public ?string $filledVersion = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Before the form is filled: version() loads the links, and the form's
     * link fields then fill from those, so both see the same ones.
     */
    protected function beforeFill(): void
    {
        $this->filledVersion = $this->getRecord()->version();
    }

    /**
     * On the locked row and links, inside the transaction the panel puts
     * every save in (databaseTransactions() in AdminPanelProvider): nothing
     * can slip in between this check and the save.
     */
    protected function beforeSave(): void
    {
        if (Maintenance::locked($this->getRecord()->getKey())?->version() === $this->filledVersion) {
            return;
        }

        MaintenanceSchema::changedMeanwhile();

        $this->halt();
    }

    protected function afterSave(): void
    {
        $this->filledVersion = Maintenance::locked($this->getRecord()->getKey())?->version();
    }
}
