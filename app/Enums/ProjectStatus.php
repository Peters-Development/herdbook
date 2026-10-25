<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProjectStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Maintenance => __('Maintenance only'),
            self::Archived => __('Archived'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Maintenance => 'warning',
            self::Archived => 'gray',
        };
    }
}
