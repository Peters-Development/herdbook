<?php

namespace App\Filament\Widgets;

use App\Models\Network;

/**
 * Internet lines. Only shown when one needs attention, like hosting contracts.
 */
class NetworkRenewals extends RenewalsTableWidget
{
    protected static ?int $sort = 8;

    protected static function model(): string
    {
        return Network::class;
    }

    protected function heading(): string
    {
        return __('Internet contracts that need attention');
    }

    protected function providerLabel(): string
    {
        return __('Internet provider');
    }
}
