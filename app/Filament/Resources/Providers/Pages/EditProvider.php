<?php

namespace App\Filament\Resources\Providers\Pages;

use App\Filament\Resources\Providers\ProviderResource;
use App\Models\DomainPrice;
use App\Models\Provider;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class EditProvider extends EditRecord
{
    protected static string $resource = ProviderResource::class;

    /**
     * Its running domains that took their price from the list before this
     * save (beforeSave()).
     *
     * @var list<int>
     */
    protected array $onPriceList = [];

    /**
     * The lines of the price list are saved one by one: two that swap
     * extensions (com and nl) would meet in the unique index halfway. Those
     * that change extension first get one that is theirs alone; the save
     * then gives them their new one (and logs the change from the old).
     */
    protected function beforeSave(): void
    {
        $this->onPriceList = $this->record->registeredDomains()->usingPriceList()->whereNull('renewal_stopped_at')->pluck('id')->all();

        $extensions = collect($this->data['domainPrices'] ?? [])
            ->filter(fn (mixed $item, string|int $key): bool => is_array($item) && str_starts_with((string) $key, 'record-'))
            ->mapWithKeys(fn (array $item, string $key): array => [(int) Str::after($key, 'record-') => DomainPrice::normalize((string) ($item['extension'] ?? ''))]);

        $this->record->domainPrices()
            ->whereKey($extensions->keys())
            ->get()
            ->filter(fn (DomainPrice $price): bool => $extensions[$price->id] !== $price->extension)
            // Past the model: not a change of its own to log.
            ->each(fn (DomainPrice $price) => DomainPrice::query()->whereKey($price->id)->update(['extension' => "~{$price->id}"]));
    }

    /**
     * A line that was removed, or got another extension, leaves the domains
     * that took their price from it without one: they leave every total.
     * Say so, not just "Saved".
     */
    protected function afterSave(): void
    {
        $lost = $this->record->registeredDomains()->whereKey($this->onPriceList)->missingCost()->orderBy('name')->pluck('name');

        if ($lost->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(trans_choice(':count domain no longer has a price from the price list: it is left out of every total.|:count domains no longer have a price from the price list: they are left out of every total.', $lost->count()))
            ->body(__('Add their extension again, or give them an amount of their own: :domains', [
                'domains' => $lost->take(5)->implode(', ').($lost->count() > 5 ? ' '.__('and :count more', ['count' => $lost->count() - 5]) : ''),
            ]))
            ->persistent()
            ->send();
    }

    /**
     * Still two lines with one extension at a time (should beforeSave() miss
     * a case): a message, not a server error.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (UniqueConstraintViolationException $exception) {
            // The name is validated: anything else is no price list's.
            if (! str_contains($exception->getMessage(), 'domain_prices')) {
                throw $exception;
            }

            Notification::make()
                ->danger()
                ->title(__('Two lines of the price list would have the same extension'))
                ->body(__('Give one of them another extension and save, then change it again.'))
                ->send();
        }
    }

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
