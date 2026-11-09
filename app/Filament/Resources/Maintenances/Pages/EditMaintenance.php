<?php

namespace App\Filament\Resources\Maintenances\Pages;

use App\Filament\Resources\Concerns\GuardsConcurrentEdits;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Support\MaintenanceSchema;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditMaintenance extends EditRecord
{
    use GuardsConcurrentEdits;

    protected static string $resource = MaintenanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function changedMeanwhile(): void
    {
        MaintenanceSchema::changedMeanwhile();
    }

    /**
     * The saving hook may make the title or move the start.
     */
    protected function refreshedOnSave(): array
    {
        return ['title', 'starts_at'];
    }
}
