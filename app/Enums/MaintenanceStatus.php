<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Stored. "Overdue" is not a status of its own: a planned maintenance
 * whose time has passed (Maintenance::isOverdue()).
 */
enum MaintenanceStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => __('Planned'),
            self::Done => __('Done'),
            self::Cancelled => __('Called off'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'info',
            self::Done => 'success',
            self::Cancelled => 'gray',
        };
    }
}
