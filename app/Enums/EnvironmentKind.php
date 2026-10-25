<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EnvironmentKind: string implements HasColor, HasLabel
{
    case Production = 'production';
    case Staging = 'staging';
    case Testing = 'testing';
    case Development = 'development';

    public function getLabel(): string
    {
        return match ($this) {
            self::Production => __('Production'),
            self::Staging => __('Staging'),
            self::Testing => __('Testing'),
            self::Development => __('Development'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Production => 'danger',
            self::Staging => 'warning',
            self::Testing => 'info',
            self::Development => 'gray',
        };
    }
}
