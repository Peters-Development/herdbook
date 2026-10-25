<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Support\RenewalSchema;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

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
