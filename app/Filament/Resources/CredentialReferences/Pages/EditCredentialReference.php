<?php

namespace App\Filament\Resources\CredentialReferences\Pages;

use App\Filament\Resources\CredentialReferences\CredentialReferenceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCredentialReference extends EditRecord
{
    protected static string $resource = CredentialReferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
