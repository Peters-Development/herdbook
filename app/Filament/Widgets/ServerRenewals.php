<?php

namespace App\Filament\Widgets;

use App\Models\Server;

/**
 * Hosting contracts. Only shown when one needs attention: most servers
 * have no contract date at all.
 */
class ServerRenewals extends RenewalsTableWidget
{
    protected static ?int $sort = 6;

    protected static function model(): string
    {
        return Server::class;
    }

    protected function heading(): string
    {
        return __('Server contracts that need attention');
    }
}
