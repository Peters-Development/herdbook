<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentSeverity: string implements HasColor, HasLabel
{
    case Sev1 = 'sev1';
    case Sev2 = 'sev2';
    case Sev3 = 'sev3';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sev1 => __('SEV1 · critical'),
            self::Sev2 => __('SEV2 · major'),
            self::Sev3 => __('SEV3 · minor'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sev1 => 'danger',
            self::Sev2 => 'warning',
            self::Sev3 => 'gray',
        };
    }
}
