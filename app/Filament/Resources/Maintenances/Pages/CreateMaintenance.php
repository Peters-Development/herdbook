<?php

namespace App\Filament\Resources\Maintenances\Pages;

use App\Enums\MaintenanceStatus;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Maintenances\Schemas\MaintenanceForm;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenance extends CreateRecord
{
    protected static string $resource = MaintenanceResource::class;

    /**
     * Filament fills the second form by casting the default "now" a second
     * time, which puts it an extra UTC offset ahead.
     */
    protected static bool $canCreateAnother = false;

    /** "Schedule maintenance" while the (live) status is Planned. */
    public function getTitle(): string
    {
        return MaintenanceForm::statusFrom($this->data['status'] ?? null) === MaintenanceStatus::Planned
            ? __('Schedule maintenance')
            : parent::getTitle();
    }
}
