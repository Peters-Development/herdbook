<?php

namespace App\Filament\Resources\Providers\Pages;

use App\Filament\Resources\Providers\ProviderResource;
use App\Models\Provider;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProvider extends EditRecord
{
    protected static string $resource = ProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // Its servers, domains and subscriptions would lose their provider
            // and, with it, the lead time their renewal status is computed
            // with; a network who to call when its line is down.
            DeleteAction::make()
                ->before(function (DeleteAction $action, Provider $record): void {
                    if ($record->servers()->exists() || $record->registeredDomains()->exists() || $record->dnsDomains()->exists() || $record->subscriptions()->exists() || $record->networks()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title(__('This provider is still in use'))
                            ->body(__('Link its servers, domains, subscriptions and networks to another provider first.'))
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
