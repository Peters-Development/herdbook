<?php

namespace App\Filament\Resources\CredentialReferences\Pages;

use App\Filament\Resources\CredentialReferences\CredentialReferenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCredentialReferences extends ListRecords
{
    protected static string $resource = CredentialReferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
