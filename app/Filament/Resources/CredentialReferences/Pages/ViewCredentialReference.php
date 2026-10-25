<?php

namespace App\Filament\Resources\CredentialReferences\Pages;

use App\Filament\Resources\CredentialReferences\CredentialReferenceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCredentialReference extends ViewRecord
{
    protected static string $resource = CredentialReferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
