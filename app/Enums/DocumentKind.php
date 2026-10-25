<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentKind: string implements HasColor, HasLabel
{
    case Runbook = 'runbook';
    case Manual = 'manual';
    case Architecture = 'architecture';
    case Note = 'note';

    public function getLabel(): string
    {
        return match ($this) {
            self::Runbook => __('Runbook'),
            self::Manual => __('Manual'),
            self::Architecture => __('Architecture'),
            self::Note => __('Note'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Runbook => 'danger',
            self::Manual => 'info',
            self::Architecture => 'primary',
            self::Note => 'gray',
        };
    }
}
