<?php

namespace App\Models\Concerns;

use App\Enums\CostPeriod;
use App\Models\Client;
use App\Models\Project;
use BcMath\Number;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What something costs, excluding VAT: an amount per month or per year.
 * When it is paid follows from the period and the renewal dates
 * (RecurringCosts).
 *
 * Columns: cost_amount (decimal), cost_period.
 *
 * @property ?string $cost_amount
 * @property ?CostPeriod $cost_period
 */
trait HasCost
{
    public function initializeHasCost(): void
    {
        $this->mergeCasts([
            'cost_amount' => 'decimal:2',
            'cost_period' => CostPeriod::class,
        ]);
    }

    /**
     * Whether it can cost anything at all: a local domain cannot (Domain).
     */
    public function canHaveCost(): bool
    {
        return true;
    }

    /**
     * The amount that counts: its own. A domain without one takes the price
     * list of its registrar (Domain::costAmount()). Read this, not the
     * column.
     */
    public function costAmount(): ?string
    {
        return $this->cost_amount;
    }

    /**
     * The period of costAmount().
     */
    public function costPeriod(): ?CostPeriod
    {
        return $this->cost_period;
    }

    /**
     * Where costAmount() comes from when it is not its own ("From the price
     * list of Gandi"); null when it is.
     */
    public function costSource(): ?string
    {
        return null;
    }

    /**
     * What costAmount() reads besides the item itself, to eager-load it.
     *
     * @return list<string>
     */
    public static function costAmountRelations(): array
    {
        return [];
    }

    /**
     * What it costs per year in SQL, to sort on: a monthly amount twelve
     * times.
     *
     * @param  Builder<static>  $query
     * @return array{string, list<mixed>} the expression and its bindings
     */
    public static function yearlyCostSql(Builder $query): array
    {
        return [
            "{$query->qualifyColumn('cost_amount')} * (case when {$query->qualifyColumn('cost_period')} = ? then 12 else 1 end)",
            [CostPeriod::Month->value],
        ];
    }

    /**
     * An amount without a period says nothing about a year or a month.
     */
    public function hasCost(): bool
    {
        return $this->costAmount() !== null && $this->costPeriod() !== null;
    }

    /**
     * A monthly amount twelve times.
     */
    public function yearlyCost(): ?Number
    {
        return $this->hasCost() ? $this->costPeriod()->perYear(new Number($this->costAmount())) : null;
    }

    /**
     * The projects it is for (Renewable::costProjects()).
     *
     * @return Collection<int, Project>
     */
    abstract public function costProjects(): Collection;

    /**
     * The clients of its projects; without projects, ours (null). Domains
     * and subscriptions say whose they are instead.
     *
     * @return Collection<int, ?Client>
     */
    public function costClients(): Collection
    {
        return $this->costProjects()
            ->map(fn (Project $project): ?Client => $project->client)
            ->whenEmpty(fn (Collection $none): Collection => $none->push(null))
            ->unique(fn (?Client $client): ?int => $client?->id)
            ->values();
    }

    /**
     * What one renewal of a yearly amount costs: the yearly amount times the
     * term in years (a domain for two years: twice), to the cent. Null for
     * a monthly amount, which is paid every month instead.
     */
    public function renewalCost(): ?Number
    {
        return $this->hasCost() && $this->costPeriod() === CostPeriod::Year
            ? ($this->yearlyCost() * max(1, $this->renewal_period_months) / 12)->round(2)
            : null;
    }

    /**
     * Running (not stopped), with a contract, and no amount or no period:
     * what the cost overview cannot count yet (! hasCost()). Uses
     * HasRenewal's withContract() (every model with a cost renews).
     *
     * @param  Builder<static>  $query
     */
    public function scopeMissingCost(Builder $query): void
    {
        $query
            ->withContract()
            ->whereNull('renewal_stopped_at')
            ->where(fn (Builder $query) => $this->whereNoAmount($query));
    }

    /**
     * The SQL of ! hasCost() for one that may cost something.
     *
     * @param  Builder<static>  $query
     */
    protected function whereNoAmount(Builder $query): void
    {
        $query->whereNull('cost_amount')->orWhereNull('cost_period');
    }
}
