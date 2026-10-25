<?php

namespace App\Services\Costs;

use App\Models\Contracts\Renewable;
use BcMath\Number;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One payment: a renewal on its date, or one month of a monthly amount.
 */
final readonly class Payment
{
    public function __construct(
        public Model&Renewable $item,
        /** The first day of the month it falls in. */
        public CarbonImmutable $month,
        /** The renewal date; null for a month of a monthly amount. */
        public ?CarbonImmutable $date,
        public Number $amount,
        /** It does not renew by itself: someone decides. */
        public bool $decide,
    ) {}

    public function isMonthly(): bool
    {
        return $this->date === null;
    }

    /**
     * The renewal that is up now (on the expiry date), not one after it:
     * the item's renewal status is about this one.
     */
    public function isPending(): bool
    {
        return $this->date !== null && $this->date->equalTo($this->item->expires_on);
    }
}
