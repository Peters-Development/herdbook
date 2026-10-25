<?php

namespace App\Filament\Resources\Checks\Pages;

use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Support\CheckActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCheck extends ViewRecord
{
    protected static string $resource = CheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CheckActions::runNow(),
            CheckActions::pause(),
            CheckActions::resume(),
            EditAction::make(),
        ];
    }
}
