<?php

namespace App\Models\Concerns;

use App\Enums\RenewalPeriod;
use App\Enums\RenewalStatus;
use App\Models\Contracts\Renewable;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Something that expires and is renewed: a domain, a hosting contract, a
 * subscription, the internet line of a network.
 *
 * Columns: expires_on (date), auto_renews, renewal_period_months,
 * renewal_lead_days (null: the provider's, else config), renewal_stopped_at.
 * The status is derived from the dates on the fly (RenewalStatus).
 *
 * @property ?CarbonImmutable $expires_on
 * @property bool $auto_renews
 * @property int $renewal_period_months
 * @property ?int $renewal_lead_days
 * @property ?CarbonImmutable $renewal_stopped_at
 */
trait HasRenewal
{
    /**
     * The relation renewalProvider() reads.
     */
    abstract public static function renewalProviderRelation(): string;

    /**
     * The provider whose renewal lead time applies when the item has none.
     */
    public function renewalProvider(): ?Provider
    {
        return $this->getRelationValue(static::renewalProviderRelation());
    }

    /**
     * Whether there is a contract or registration to renew at all: a machine
     * of our own has none, so no renewal status to show.
     */
    abstract public function hasContract(): bool;

    /**
     * hasContract() in a query: a model where not everything has a contract
     * (Server) narrows it down.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithContract(Builder $query): void {}

    public function initializeHasRenewal(): void
    {
        // The column defaults, also on a model that was just created (it is
        // not reloaded, so it would not have them otherwise).
        $this->attributes += [
            'auto_renews' => true,
            'renewal_period_months' => 12,
        ];

        $this->mergeCasts([
            'expires_on' => 'date',
            'auto_renews' => 'boolean',
            'renewal_period_months' => 'integer',
            'renewal_lead_days' => 'integer',
            'renewal_stopped_at' => 'datetime',
        ]);
    }

    public function renewalLeadDays(): int
    {
        return $this->renewal_lead_days
            ?? $this->renewalProvider()?->renewal_lead_days
            ?? (int) config('herdbook.renewal.default_lead_days');
    }

    /**
     * The lead time as it applies: at most half the term. A monthly contract
     * with the default 60 days would otherwise never be "Active", not even
     * right after a renewal.
     */
    public function effectiveRenewalLeadDays(): int
    {
        if ($this->expires_on === null) {
            return $this->renewalLeadDays();
        }

        $termDays = (int) $this->expires_on->subMonthsNoOverflow($this->renewal_period_months)->diffInDays($this->expires_on);

        return min($this->renewalLeadDays(), intdiv($termDays, 2));
    }

    /**
     * Counted in calendar days, with "today" taken in the display timezone:
     * just after midnight in Amsterdam it is still yesterday in UTC.
     */
    public function renewalStatus(?CarbonInterface $today = null): RenewalStatus
    {
        if ($this->renewal_stopped_at !== null) {
            return RenewalStatus::Stopped;
        }

        if ($this->expires_on === null) {
            return RenewalStatus::Unknown;
        }

        $today ??= Date::now(config('herdbook.display_timezone'));
        $daysLeft = (int) Date::parse($today->toDateString())
            ->diffInDays(Date::parse($this->expires_on->toDateString()), false);

        if ($daysLeft < 0) {
            return RenewalStatus::Expired;
        }

        $leadDays = $this->effectiveRenewalLeadDays();

        if ($daysLeft > $leadDays) {
            return RenewalStatus::Active;
        }

        if (! $this->auto_renews) {
            return RenewalStatus::Decide;
        }

        // The provider renews $leadDays before expiry; after that, the renewal
        // may stay unconfirmed for $graceDays before it asks for a check.
        $graceDays = min((int) config('herdbook.renewal.confirmation_grace_days'), intdiv($leadDays, 2));

        return $daysLeft > $leadDays - $graceDays
            ? RenewalStatus::RenewalExpected
            : RenewalStatus::NeedsCheck;
    }

    public function canBeRenewed(): bool
    {
        return $this->expires_on !== null && $this->renewal_stopped_at === null;
    }

    /**
     * The expiry date after one renewal: counted from the current expiry
     * date, not from today, so renewing a few days late shifts nothing.
     * NoOverflow keeps 29 February on 28 February instead of 1 March.
     */
    public function renewedExpiryDate(): ?CarbonImmutable
    {
        return $this->expires_on?->addMonthsNoOverflow($this->renewal_period_months);
    }

    /**
     * "1 month", "6 months", "1 year", "2 years".
     */
    public function renewalTerm(): string
    {
        return RenewalPeriod::label($this->renewal_period_months);
    }

    public function renew(): void
    {
        if (! $this->canBeRenewed()) {
            throw new LogicException('Only an item with an expiry date that is not stopped can be renewed.');
        }

        DB::transaction(function (): void {
            $previous = $this->expires_on;
            $this->expires_on = $this->renewedExpiryDate();

            // One readable "renewed" entry instead of a generic "updated" one.
            $this->disableLogging();

            try {
                $this->save();
            } finally {
                $this->enableLogging();
            }

            $this->logRenewal($previous);
        });
    }

    /**
     * The description is the untranslated source text with the date as a
     * property: ActivityPresenter reads it in the viewer's language.
     */
    private function logRenewal(CarbonImmutable $previous): void
    {
        activity()
            ->performedOn($this)
            ->event('renewed')
            ->withChanges([
                'attributes' => ['expires_on' => $this->expires_on->toDateString()],
                'old' => ['expires_on' => $previous->toDateString()],
            ])
            ->withProperty('date', $this->expires_on->toDateString())
            ->log(Renewable::RENEWED);
    }

    public function stopRenewal(): void
    {
        $this->update(['renewal_stopped_at' => now()]);
    }

    public function resumeRenewal(): void
    {
        $this->update(['renewal_stopped_at' => null]);
    }
}
