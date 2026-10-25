<?php

namespace App\Services\Costs;

use App\Enums\CostPeriod;
use App\Enums\ProjectStatus;
use App\Enums\ServerStatus;
use App\Models\Client;
use App\Models\Contracts\Renewable;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use BcMath\Number;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * What domains, servers, subscriptions and internet lines cost: per year,
 * per month for the coming twelve months, and per provider, project and
 * client.
 *
 * - A monthly amount is paid every month (this month in full); once
 *   stopped, until the expiry date, the month it falls in in full.
 * - A yearly amount is paid at every renewal: on the expiry date and every
 *   term after it, the yearly amount times the term in years. Once stopped,
 *   nothing more. Without an expiry date it falls in no month.
 * - What was paid already is not known: renewing moved the expiry date on.
 *
 * Scoped (AppServiceProvider): computed once per request or job, day and language.
 */
final class RecurringCosts
{
    private const int MONTHS = 12;

    /** @var array<string, CostOverview> */
    private array $computed = [];

    public function overview(?CarbonInterface $today = null): CostOverview
    {
        // Calendar days, as for renewals: "today" in the display timezone.
        $today = Date::parse(($today ?? Date::now(config('herdbook.display_timezone')))->toDateString());

        // Per language too: the groups carry translated labels ("Shared").
        return $this->computed[$today->toDateString().' '.app()->getLocale()] ??= $this->build($today);
    }

    private function build(CarbonImmutable $today): CostOverview
    {
        $all = $this->items();
        $items = $all->filter(fn (Renewable $item): bool => $item->hasCost())->values();
        // As HasCost::scopeMissingCost(), the filter behind "without an amount".
        $unpriced = $all->filter(fn (Renewable $item): bool => $item->hasContract() && $item->renewal_stopped_at === null && ! $item->hasCost())->values();
        $first = $today->startOfMonth();
        $end = $first->addMonths(self::MONTHS);

        $running = $items->filter(fn (Renewable $item): bool => $item->renewal_stopped_at === null)->values();
        $payments = $items->flatMap(fn (Model&Renewable $item): array => $this->payments($item, $first, $end));
        $months = [];

        for ($month = $first; $month->lt($end); $month = $month->addMonth()) {
            $months[] = new CostMonth($month, $payments
                ->filter(fn (Payment $payment): bool => $payment->month->equalTo($month))
                ->sortBy(fn (Payment $payment): string => ($payment->date?->toDateString() ?? '').' '.$payment->item->name, SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all());
        }

        return new CostOverview(
            today: $today,
            yearly: Money::sum($running->map(fn (Renewable $item): Number => $item->yearlyCost())),
            counted: $running->count(),
            months: $months,
            expired: $running->filter(fn (Renewable $item): bool => $item->expires_on?->lt($today) ?? false)->sortBy('expires_on')->values()->all(),
            undated: $running->filter(fn (Renewable $item): bool => $item->cost_period === CostPeriod::Year && $item->expires_on === null)->values()->all(),
            unused: $running->filter(fn (Renewable $item): bool => $item instanceof Server && $item->status === ServerStatus::Decommissioned)->values()->all(),
            archived: $running->filter($this->onlyForArchived(...))->values()->all(),
            unpriced: $unpriced->all(),
            byProvider: $this->group($running, fn (Renewable $item): array => ($provider = $item->renewalProvider()) ? [[$provider, $provider->name]] : [], __('No provider')),
            byProject: $this->group($running, fn (Renewable $item): array => $item->costProjects()->map(fn (Project $project): array => [$project, $project->name])->all(), __('No project')),
            byClient: $this->group($running, fn (Renewable $item): array => $item->costClients()->map(fn (?Client $client): array => [$client, $client->name ?? __('Own')])->all(), __('Own')),
        );
    }

    /**
     * Every renewable item (a few hundred at most), with what the overview
     * needs of it.
     *
     * @return Collection<int, Model&Renewable>
     */
    private function items(): Collection
    {
        return collect(Renewable::MODELS)->flatMap(fn (string $model): Collection => $model::query()
            ->with([$model::renewalProviderRelation(), ...$model::costRelations()])
            ->get());
    }

    /**
     * The payments of $item from $first until $end.
     *
     * @return list<Payment>
     */
    private function payments(Model&Renewable $item, CarbonImmutable $first, CarbonImmutable $end): array
    {
        $stopped = $item->renewal_stopped_at !== null;
        $decide = ! $item->auto_renews && ! $stopped;
        $payments = [];

        if ($stopped && $item->expires_on === null) {
            return [];
        }

        if ($item->cost_period === CostPeriod::Month) {
            $amount = new Number($item->cost_amount);
            // The contract pays the months up to its expiry whatever is decided;
            // from the month it expires in, someone decides.
            $decidedFrom = $item->expires_on?->startOfMonth() ?? $first;
            $until = $stopped ? min($item->expires_on->startOfMonth()->addMonth(), $end) : $end;

            for ($month = $first; $month->lt($until); $month = $month->addMonth()) {
                $payments[] = new Payment($item, $month, null, $amount, $decide && $month->gte($decidedFrom));
            }

            return $payments;
        }

        if ($stopped || $item->expires_on === null) {
            return [];
        }

        $term = max(1, $item->renewal_period_months);
        $amount = $item->renewalCost();

        // Expired before this month: that renewal falls in no month (it is in
        // CostOverview::$expired), the ones after it do.
        for ($n = 0, $date = $item->expires_on; $date->lt($end); $date = $item->expires_on->addMonthsNoOverflow(++$n * $term)) {
            if ($date->gte($first)) {
                $payments[] = new Payment($item, $date->startOfMonth(), $date, $amount, $decide);
            }
        }

        return $payments;
    }

    /**
     * For archived projects only, or (without projects) for archived
     * clients only: probably not needed any more. Not an internet line: the
     * office needs it whatever runs there.
     */
    private function onlyForArchived(Renewable $item): bool
    {
        if ($item instanceof Network) {
            return false;
        }

        $projects = $item->costProjects();

        if ($projects->isNotEmpty()) {
            return $projects->every(fn (Project $project): bool => $project->status === ProjectStatus::Archived);
        }

        return $item->costClients()->every(fn (?Client $client): bool => $client?->archived_at !== null);
    }

    /**
     * The running items per group, the most expensive group first. An item
     * for more than one group is shared: counted once, under "Shared", and
     * shown with each of its groups too.
     *
     * @param  Collection<int, Model&Renewable>  $items
     * @param  callable(Model&Renewable): list<array{0: ?Model, 1: string}>  $groupsOf  the groups an item is for: their model (null: a bucket) and label
     * @param  string  $none  the bucket of an item for no group
     * @return list<CostGroup>
     */
    private function group(Collection $items, callable $groupsOf, string $none): array
    {
        $groups = [];
        $key = fn (?Model $model, string $label): string => $model ? $model->getMorphClass().':'.$model->getKey() : $label;
        $add = function (?Model $model, string $label, string $list, CostLine $line) use (&$groups, $key): void {
            $groups[$key($model, $label)] ??= ['model' => $model, 'label' => $label, 'items' => [], 'shared' => []];
            $groups[$key($model, $label)][$list][] = $line;
        };

        foreach ($items as $item) {
            $owners = $groupsOf($item);

            if (count($owners) <= 1) {
                [$model, $label] = $owners[0] ?? [null, $none];
                $add($model, $label, 'items', new CostLine($item, $item->yearlyCost()));

                continue;
            }

            $add(null, __('Shared'), 'items', new CostLine($item, $item->yearlyCost(), self::labels($owners)));

            foreach ($owners as [$model, $label]) {
                $others = array_filter($owners, fn (array $owner): bool => $key(...$owner) !== $key($model, $label));
                $add($model, $label, 'shared', new CostLine($item, $item->yearlyCost(), __('with :names', ['names' => self::labels($others)])));
            }
        }

        $sorted = fn (array $lines): array => collect($lines)
            ->sort(fn (CostLine $a, CostLine $b): int => ($b->yearly <=> $a->yearly) ?: strnatcasecmp($a->item->name, $b->item->name))
            ->values()
            ->all();

        return collect($groups)
            ->map(fn (array $group): CostGroup => new CostGroup(
                $group['label'],
                $group['model'],
                Money::sum(array_map(fn (CostLine $line): Number => $line->yearly, $group['items'])),
                $sorted($group['items']),
                $sorted($group['shared']),
            ))
            ->sort(fn (CostGroup $a, CostGroup $b): int => ($b->total <=> $a->total) ?: ($b->sharedTotal() <=> $a->sharedTotal()) ?: strnatcasecmp($a->label, $b->label))
            ->values()
            ->all();
    }

    /**
     * "Studbook, Pasture Planner".
     *
     * @param  array<array{0: ?Model, 1: string}>  $owners
     */
    private static function labels(array $owners): string
    {
        return collect($owners)->pluck(1)->sort(SORT_NATURAL | SORT_FLAG_CASE)->join(', ');
    }
}
