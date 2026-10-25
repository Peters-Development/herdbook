<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Support\DeleteConsequences;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditNetwork extends EditRecord
{
    protected static string $resource = NetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteConsequences::network(),
        ];
    }

    /**
     * The subnet is stored as its network address ("192.168.60.7/24" →
     * "192.168.60.0/24"): show what was saved.
     */
    protected function afterSave(): void
    {
        $this->refreshFormData(['subnet']);
    }
}
