<?php

namespace App\Filament\Resources\Providers\Pages;

use App\Filament\Resources\Providers\ProviderResource;
use App\Models\Provider;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewProvider extends ViewRecord
{
    protected static string $resource = ProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::usePriceListAction(),
            EditAction::make(),
        ];
    }

    /**
     * Its domains with an amount of their own that is just the list price
     * would not follow a price change: clear their amount, once.
     */
    private static function usePriceListAction(): Action
    {
        return Action::make('usePriceList')
            ->label(__('Use the price list'))
            ->icon(Heroicon::OutlinedListBullet)
            ->color('gray')
            ->visible(fn (Provider $record): bool => $record->domainsAtListPrice()->isNotEmpty())
            ->requiresConfirmation()
            ->modalHeading(__('Use the price list'))
            ->modalDescription(fn (Provider $record): string => trans_choice(
                ':count domain has an amount of its own that equals its price in the list, so it would not follow a price change. Clear its amount, so it uses the price list?|:count domains have an amount of their own that equals their price in the list, so they would not follow a price change. Clear their amount, so they use the price list?',
                $record->domainsAtListPrice()->count(),
            ))
            ->modalSubmitActionLabel(__('Use the price list'))
            ->action(function (Provider $record): void {
                $domains = $record->domainsAtListPrice();
                $domains->each->usePriceList();

                Notification::make()->success()->title(trans_choice(':count domain now uses the price list.|:count domains now use the price list.', $domains->count()))->send();
            });
    }
}
