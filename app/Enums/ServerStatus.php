<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ServerStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Decommissioned = 'decommissioned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Maintenance => __('Maintenance'),
            self::Decommissioned => __('Decommissioned'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Maintenance => 'warning',
            self::Decommissioned => 'gray',
        };
    }
}
