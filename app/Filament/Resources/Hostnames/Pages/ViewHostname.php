<?php

namespace App\Filament\Resources\Hostnames\Pages;

use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Support\CheckActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewHostname extends ViewRecord
{
    protected static string $resource = HostnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CheckActions::enableMonitoring(),
            EditAction::make(),
        ];
    }
}
