<?php

namespace App\Filament\Widgets;

use App\Models\Subscription;

/**
 * Subscriptions. Only shown when one needs attention, like hosting contracts.
 */
class SubscriptionRenewals extends RenewalsTableWidget
{
    protected static ?int $sort = 7;

    protected static function model(): string
    {
        return Subscription::class;
    }

    protected function heading(): string
    {
        return __('Subscriptions that need attention');
    }
}
