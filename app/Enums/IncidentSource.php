<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IncidentSource: string implements HasLabel
{
    case Manual = 'manual';
    case Import = 'import';
    case Check = 'check';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => __('By hand'),
            self::Import => __('Imported'),
            self::Check => __('Check'),
        };
    }
}
