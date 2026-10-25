<?php

namespace App\Services\Costs;

use BcMath\Number;
use Carbon\CarbonImmutable;

/**
 * The payments of one calendar month.
 */
final readonly class CostMonth
{
    /**
     * @param  list<Payment>  $payments
     */
    public function __construct(
        public CarbonImmutable $start,
        public array $payments,
    ) {}

    public function key(): string
    {
        return $this->start->format('Y-m');
    }

    public function total(): Number
    {
        return self::sum($this->payments);
    }

    /** Monthly amounts that run on by themselves. */
    public function monthly(): Number
    {
        return self::sum(array_filter($this->payments, fn (Payment $payment): bool => $payment->isMonthly() && ! $payment->decide));
    }

    /** Renewals that happen by themselves. */
    public function renewals(): Number
    {
        return self::sum(array_filter($this->payments, fn (Payment $payment): bool => ! $payment->isMonthly() && ! $payment->decide));
    }

    /** What does not renew by itself. */
    public function decide(): Number
    {
        return self::sum(array_filter($this->payments, fn (Payment $payment): bool => $payment->decide));
    }

    /**
     * @param  iterable<Payment>  $payments
     */
    public static function sum(iterable $payments): Number
    {
        return Money::sum(array_map(fn (Payment $payment): Number => $payment->amount, [...$payments]));
    }
}
