<?php

namespace App\Filament\Resources\Hostnames\Pages;

use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Support\DeleteConsequences;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditHostname extends EditRecord
{
    protected static string $resource = HostnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteConsequences::hostname(),
        ];
    }
}
