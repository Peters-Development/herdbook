<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Support\DeleteConsequences;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    /**
     * With a DNS server, the model set the DNS host (Domain::booted()), not
     * the hidden field: refresh it, or clearing the server next shows the old
     * one and saves it back.
     */
    protected function afterSave(): void
    {
        $this->refreshFormData(['dns_provider_id']);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteConsequences::domain(),
        ];
    }
}
