<?php

namespace App\Filament\Resources\Maintenances\Pages;

use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Support\MaintenanceSchema;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMaintenance extends ViewRecord
{
    protected static string $resource = MaintenanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MaintenanceSchema::completeAction(),
            MaintenanceSchema::cancelAction(),
            EditAction::make(),
        ];
    }
}
