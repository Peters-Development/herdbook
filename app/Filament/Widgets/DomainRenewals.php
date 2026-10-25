<?php

namespace App\Filament\Widgets;

use App\Models\Domain;

class DomainRenewals extends RenewalsTableWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return true;
    }

    protected static function model(): string
    {
        return Domain::class;
    }

    protected function heading(): string
    {
        return __('Domains that need attention');
    }

    protected function providerLabel(): string
    {
        return __('Registrar');
    }
}
