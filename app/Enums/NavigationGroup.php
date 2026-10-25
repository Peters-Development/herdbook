<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The groups in the panel's menu, in this order. An enum rather than strings:
 * Filament asks for the label while it builds the menu, so it is in the
 * language of the signed-in account, not the one the panel booted with.
 */
enum NavigationGroup implements HasLabel
{
    case Inventory;
    case Knowledge;
    case Settings;

    public function getLabel(): string
    {
        return match ($this) {
            self::Inventory => __('Inventory'),
            self::Knowledge => __('Knowledge'),
            self::Settings => __('Settings'),
        };
    }
}
