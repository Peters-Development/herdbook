<?php

namespace App\Enums;

use App\Models\Concerns\HasRenewal;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a renewable item (a domain, a hosting contract, a subscription)
 * stands. Derived from its dates on the fly, never stored: it changes with
 * the calendar.
 *
 * @see HasRenewal::renewalStatus()
 */
enum RenewalStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case RenewalExpected = 'renewal_expected';
    case NeedsCheck = 'needs_check';
    case Decide = 'decide';
    case Expired = 'expired';
    case Stopped = 'stopped';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::RenewalExpected => __('Renewal expected'),
            self::NeedsCheck => __('Unconfirmed'),
            self::Decide => __('Decision needed'),
            self::Expired => __('Expired'),
            self::Stopped => __('Cancelled'),
            self::Unknown => __('No date'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::RenewalExpected => 'info',
            self::NeedsCheck, self::Decide => 'warning',
            self::Expired => 'danger',
            self::Stopped, self::Unknown => 'gray',
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::RenewalExpected, self::NeedsCheck, self::Decide, self::Expired], true);
    }
}
