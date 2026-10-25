<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Monitoring => __('Watching'),
            self::Resolved => __('Resolved'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Monitoring => 'warning',
            self::Resolved => 'success',
        };
    }
}
