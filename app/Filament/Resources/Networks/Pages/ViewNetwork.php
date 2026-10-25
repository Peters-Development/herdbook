<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Support\RenewalSchema;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNetwork extends ViewRecord
{
    protected static string $resource = NetworkResource::class;

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
