<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Support\RenewalSchema;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

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
