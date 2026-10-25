<?php

namespace App\Services\Costs;

use App\Enums\CostRange;
use App\Models\Client;
use App\Models\Contracts\Renewable;
use App\Models\Project;
use App\Models\Server;
use BcMath\Number;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the cost page shows, computed once (RecurringCosts).
 */
final readonly class CostOverview
{
    /**
     * @param  Number  $yearly  per year, of everything with an amount that is not stopped
     * @param  int  $counted  how many items that is
     * @param  list<CostMonth>  $months  this month and the eleven after it
     * @param  list<Model&Renewable>  $expired  neither renewed nor stopped, the oldest first
     * @param  list<Model&Renewable>  $undated  yearly amounts without an expiry date: in no month
     * @param  list<Server>  $unused  out of use, but still paid for
     * @param  list<Model&Renewable>  $archived  for archived projects or clients only
     * @param  list<Model&Renewable>  $unpriced  running, with a contract, but no amount or period: counted nowhere
     * @param  list<CostGroup>  $byProvider
     * @param  list<CostGroup>  $byProject
     * @param  list<CostGroup>  $byClient
     */
    public function __construct(
        public CarbonImmutable $today,
        public Number $yearly,
        public int $counted,
        public array $months,
        public array $expired,
        public array $undated,
        public array $unused,
        public array $archived,
        public array $unpriced,
        public array $byProvider,
        public array $byProject,
        public array $byClient,
    ) {}

    /**
     * Per model, how many have no amount or period.
     *
     * @return array<class-string<Model&Renewable>, int>
     */
    public function missing(): array
    {
        return collect(Renewable::MODELS)
            ->mapWithKeys(fn (string $model): array => [$model => count(array_filter($this->unpriced, fn (Renewable $item): bool => $item instanceof $model))])
            ->all();
    }

    /**
     * What one project or client costs (byProject, byClient); empty when
     * nothing with an amount is for it.
     */
    public function groupFor(Project|Client $owner): CostGroup
    {
        $groups = $owner instanceof Project ? $this->byProject : $this->byClient;

        return collect($groups)->first(fn (CostGroup $group): bool => $owner->is($group->model))
            ?? new CostGroup($owner->name, $owner, new Number(0), []);
    }

    /**
     * What is for $owner (alone or shared) but has no amount yet.
     *
     * @return list<Model&Renewable>
     */
    public function unpricedFor(Project|Client $owner): array
    {
        return array_values(array_filter($this->unpriced, fn (Renewable $item): bool => ($owner instanceof Project ? $item->costProjects() : $item->costClients())
            ->contains(fn (?Model $model): bool => $owner->is($model))));
    }

    /**
     * @return list<CostMonth>
     */
    public function range(CostRange $range): array
    {
        return match ($range) {
            CostRange::Month => array_slice($this->months, 0, 1),
            // Up to and including the last month of this quarter.
            CostRange::Quarter => array_slice($this->months, 0, 3 - ($this->today->month - 1) % 3),
            CostRange::TwelveMonths => $this->months,
        };
    }

    /**
     * One of the twelve months, by "2026-11".
     */
    public function month(string $key): ?CostMonth
    {
        foreach ($this->months as $month) {
            if ($month->key() === $key) {
                return $month;
            }
        }

        return null;
    }

    /**
     * @param  list<CostMonth>  $months
     */
    public static function total(array $months): Number
    {
        return Money::sum(array_map(fn (CostMonth $month): Number => $month->total(), $months));
    }

    /**
     * @param  list<CostMonth>  $months
     */
    public static function decide(array $months): Number
    {
        return Money::sum(array_map(fn (CostMonth $month): Number => $month->decide(), $months));
    }
}
