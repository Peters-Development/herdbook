<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CheckState: string implements HasColor, HasLabel
{
    case Unknown = 'unknown';
    case Up = 'up';
    case Pending = 'pending';
    case Down = 'down';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unknown => __('Not known yet'),
            self::Up => __('Up'),
            self::Pending => __('Possibly down'),
            self::Down => __('Down'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Up => 'success',
            self::Pending => 'warning',
            self::Down => 'danger',
        };
    }
}
