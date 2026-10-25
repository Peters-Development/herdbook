<?php

namespace App\Filament\Pages;

use App\Enums\CostPeriod;
use App\Enums\CostRange;
use App\Enums\RenewalStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Support\CostSchema;
use App\Filament\Support\RenewalSchema;
use App\Models\Client;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Services\Costs\CostGroup;
use App\Services\Costs\CostMonth;
use App\Services\Costs\CostOverview;
use App\Services\Costs\Money;
use App\Services\Costs\Payment;
use App\Services\Costs\RecurringCosts;
use BackedEnum;
use BcMath\Number;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

/**
 * What it all costs: per year, what is coming this month, this quarter and
 * the twelve months ahead (RecurringCosts), and per provider, project and
 * client. A bar opens its month.
 */
class Costs extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'costs';

    protected string $view = 'filament.pages.costs';

    /**
     * A range (CostRange) or one month ("2026-11").
     */
    #[Url(as: 'period')]
    public string $period = CostRange::Month->value;

    public static function getNavigationLabel(): string
    {
        return __('Costs');
    }

    public function getTitle(): string
    {
        return __('Costs');
    }

    public function getSubheading(): string
    {
        return __('Amounts excluding VAT.');
    }

    /**
     * A tile or a bar: an open month clicked again goes back to this month.
     */
    public function select(string $period): void
    {
        $this->period = $period === $this->period && CostRange::tryFrom($period) === null ? CostRange::Month->value : $period;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $overview = app(RecurringCosts::class)->overview();
        $month = $overview->month($this->period);

        // This month's bar is the tile "This month", also from a link.
        if ($month === $overview->months[0]) {
            [$month, $this->period] = [null, CostRange::Month->value];
        }

        $range = CostRange::tryFrom($this->period) ?? ($month ? null : CostRange::Month);
        $months = $month ? [$month] : $overview->range($range);

        return [
            'yearly' => [
                'value' => Money::format($overview->yearly),
                'sub' => trans_choice(':amount per month on average, :count item; cancelled items are not counted|:amount per month on average, :count items; cancelled items are not counted', $overview->counted, [
                    'amount' => Money::format($overview->yearly / 12),
                ]),
            ],
            'tiles' => array_map(fn (CostRange $option): array => $this->tile($overview, $option, $option === $range), CostRange::cases()),
            'bars' => $this->bars($overview, $month),
            'heading' => match ($range) {
                null => __('Payments in :month', ['month' => $month->start->isoFormat('MMMM YYYY')]),
                CostRange::Month => __('Payments this month'),
                CostRange::Quarter => __('Payments this quarter'),
                CostRange::TwelveMonths => __('Payments in the next 12 months'),
            },
            'renewals' => $this->renewals($months, $overview),
            'monthly' => $this->monthly($months, $overview),
            'total' => CostOverview::total($months),
            'spansMonths' => count($months) > 1,
            'groups' => [
                __('By provider') => $this->groups($overview->byProvider),
                __('By project') => $this->groups($overview->byProject),
                __('By client') => $this->groups($overview->byClient),
            ],
            'todo' => $this->todo($overview),
        ];
    }

    /**
     * @return array{range: string, label: string, value: string, sub: string, active: bool}
     */
    private function tile(CostOverview $overview, CostRange $range, bool $active): array
    {
        $months = $overview->range($range);
        $decide = CostOverview::decide($months);
        $span = count($months) === 1
            ? $months[0]->start->isoFormat('MMMM')
            : $months[0]->start->isoFormat('MMM').' – '.end($months)->start->isoFormat('MMM YYYY');

        return [
            'range' => $range->value,
            'label' => $range->getLabel(),
            'value' => Money::format(CostOverview::total($months)),
            'sub' => $decide->compare(0) > 0 ? __(':period, of which :amount to decide', ['period' => $span, 'amount' => Money::format($decide)]) : $span,
            'active' => $active,
        ];
    }

    /**
     * The twelve months as stacked bars, in percent of the highest month.
     *
     * @return list<array<string, mixed>>
     */
    private function bars(CostOverview $overview, ?CostMonth $selected): array
    {
        $max = array_reduce($overview->months, fn (Number $max, CostMonth $month): Number => max($max, $month->total()), new Number(0));
        $share = fn (Number $amount): string => self::percent($amount, $max);

        return array_map(function (CostMonth $month) use ($overview, $selected, $share): array {
            $start = $month->start;
            $first = $month === $overview->months[0];

            return [
                'key' => $month->key(),
                'label' => $start->isoFormat('MMM'),
                'year' => $first || $start->month === 1 ? $start->isoFormat('YYYY') : null,
                'total' => Money::format($month->total(), 0),
                'title' => __(':month: :total (monthly :monthly, renewals :renewals, to decide :decide)', [
                    'month' => $start->isoFormat('MMMM YYYY'),
                    'total' => Money::format($month->total()),
                    'monthly' => Money::format($month->monthly()),
                    'renewals' => Money::format($month->renewals()),
                    'decide' => Money::format($month->decide()),
                ]),
                'segments' => [
                    ['tone' => 'monthly', 'height' => $share($month->monthly())],
                    ['tone' => 'renewals', 'height' => $share($month->renewals())],
                    ['tone' => 'decide', 'height' => $share($month->decide())],
                ],
                'selected' => $month === $selected,
            ];
        }, $overview->months);
    }

    /**
     * The renewals in $months, by date.
     *
     * @param  list<CostMonth>  $months
     * @return list<array<string, mixed>>
     */
    private function renewals(array $months, CostOverview $overview): array
    {
        $lines = [];

        foreach ($months as $month) {
            foreach ($month->payments as $payment) {
                if (! $payment->isMonthly()) {
                    $lines[] = [
                        ...CostSchema::describe($payment->item),
                        'date' => $payment->date->translatedFormat(__('formats.date')),
                        'term' => $payment->item->renewalTerm(),
                        'amount' => Money::format($payment->amount),
                        'badge' => $this->badge($payment, $overview),
                    ];
                }
            }
        }

        return $lines;
    }

    /**
     * The monthly amounts in $months, one line per item.
     *
     * @param  list<CostMonth>  $months
     * @return list<array<string, mixed>>
     */
    private function monthly(array $months, CostOverview $overview): array
    {
        $lines = [];

        foreach ($months as $month) {
            foreach ($month->payments as $payment) {
                if ($payment->isMonthly()) {
                    $key = $payment->item->getMorphClass().':'.$payment->item->getKey();
                    $lines[$key] ??= ['payment' => $payment, 'months' => 0, 'total' => new Number(0), 'decide' => new Number(0)];
                    $lines[$key]['months']++;
                    $lines[$key]['total'] += $payment->amount;
                    $lines[$key]['decide'] += $payment->decide ? $payment->amount : 0;
                }
            }
        }

        return array_values(array_map(fn (array $line): array => [
            ...CostSchema::describe($line['payment']->item),
            'perMonth' => Money::format($line['payment']->amount),
            'months' => $line['months'],
            'amount' => Money::format($line['total']),
            // Part of the period may be paid by the running contract (RecurringCosts::payments()).
            'decide' => $line['decide']->compare(0) > 0 && $line['decide']->compare($line['total']) < 0 ? __('of which :amount to decide', ['amount' => Money::format($line['decide'])]) : null,
            'badge' => $this->badge($line['payment'], $overview, $line['decide']->compare(0) > 0),
        ], collect($lines)->sortBy(fn (array $line): string => $line['payment']->item->name, SORT_NATURAL | SORT_FLAG_CASE)->all()));
    }

    /**
     * The renewal status when it asks for something, that it is stopped, or
     * that it is to be decided; nothing for what simply runs on. One word
     * for deciding: the status "Decision needed" is "To decide" here, as in the
     * chart, and grey while it is not up yet.
     *
     * @return ?array{label: string, color: string}
     */
    private function badge(Payment $payment, CostOverview $overview, bool $decide = false): ?array
    {
        $item = $payment->item;

        if ($item->renewal_stopped_at !== null) {
            return ['label' => RenewalStatus::Stopped->getLabel(), 'color' => RenewalStatus::Stopped->getColor()];
        }

        if (($payment->isPending() || $payment->isMonthly()) && $item->hasContract() && ($status = $item->renewalStatus($overview->today))->needsAttention()) {
            return $status === RenewalStatus::Decide
                ? ['label' => __('To decide'), 'color' => $status->getColor()]
                : ['label' => $status->getLabel(), 'color' => $status->getColor()];
        }

        return $payment->decide || $decide ? ['label' => __('To decide'), 'color' => 'gray'] : null;
    }

    /**
     * @param  list<CostGroup>  $groups
     * @return list<array<string, mixed>>
     */
    private function groups(array $groups): array
    {
        $max = $groups === [] ? new Number(0) : $groups[0]->total;

        return array_map(fn (CostGroup $group): array => [
            'label' => $group->label,
            'bucket' => $group->model === null,
            'url' => $this->groupUrl($group->model),
            // Only shared costs: it costs something, just not on its own.
            'total' => $group->total->compare(0) === 0 && $group->shared !== [] ? __('shared only') : Money::format($group->total),
            'share' => self::percent($group->total, $max),
            'shared' => $group->shared === [] ? null : __('+ :amount shared with others', ['amount' => Money::format($group->sharedTotal())]),
            'items' => array_map(CostSchema::line(...), $group->items),
            'sharedItems' => array_map(CostSchema::line(...), $group->shared),
        ], $groups);
    }

    private function groupUrl(?Model $model): ?string
    {
        return match (true) {
            $model instanceof Provider => ProviderResource::getUrl('view', ['record' => $model]),
            $model instanceof Project => ProjectResource::getUrl('view', ['record' => $model]),
            $model instanceof Client => ClientResource::getUrl('view', ['record' => $model]),
            default => null,
        };
    }

    /**
     * What keeps the numbers from being complete or right.
     *
     * @return list<array{text: string, url: ?string, color: string}>
     */
    private function todo(CostOverview $overview): array
    {
        $todo = [];

        foreach ($overview->missing() as $model => $count) {
            if ($count > 0) {
                $resource = RenewalSchema::resource($model);

                $todo[] = [
                    'text' => trans_choice(':count :label has no amount or period: it is left out of every total.|:count :label have no amount or period: they are left out of every total.', $count, [
                        'label' => $count === 1 ? $resource::getModelLabel() : $resource::getPluralModelLabel(),
                    ]),
                    'url' => $resource::getUrl('index', ['filters' => ['missing_cost' => ['isActive' => true]]]),
                    'color' => 'gray',
                ];
            }
        }

        foreach ($overview->expired as $item) {
            $todo[] = [
                'text' => __(':name expired on :date and was not renewed or cancelled (:amount).', [
                    'name' => $item->name,
                    'date' => $item->expires_on->translatedFormat(__('formats.date')),
                    'amount' => $item->cost_period === CostPeriod::Month ? Money::describe($item) : Money::format($item->renewalCost()),
                ]),
                'url' => RenewalSchema::url($item),
                'color' => 'danger',
            ];
        }

        // A machine of our own has no contract to stop (Server::hasContract()): its amount goes.
        foreach ($overview->unused as $server) {
            $todo[] = [
                'text' => $server->hasContract()
                    ? __(':name is out of use, but not cancelled: :amount.', ['name' => $server->name, 'amount' => Money::describe($server)])
                    : __(':name is out of use, but still counts: :amount. Remove the amount if you no longer pay for it.', ['name' => $server->name, 'amount' => Money::describe($server)]),
                'url' => RenewalSchema::url($server),
                'color' => 'warning',
            ];
        }

        foreach ($overview->archived as $item) {
            $todo[] = [
                'text' => __(':name is only used by archived items, but keeps running: :amount.', ['name' => $item->name, 'amount' => Money::describe($item)]),
                'url' => RenewalSchema::url($item),
                'color' => 'warning',
            ];
        }

        foreach ($overview->undated as $item) {
            $todo[] = [
                'text' => __(':name (:amount) has no expiry date: it only counts in the yearly total, in no month.', ['name' => $item->name, 'amount' => Money::describe($item)])
                    .($item->hasContract() ? '' : ' '.($item instanceof Network ? __('Choose an internet provider to fill one in.') : __('Choose a provider to fill one in.'))),
                'url' => RenewalSchema::url($item),
                'color' => 'gray',
            ];
        }

        return $todo;
    }

    /**
     * $amount as a share of $max, for a width or height in CSS.
     */
    private static function percent(Number $amount, Number $max): string
    {
        return $max->compare(0) > 0 ? (string) ($amount * 100 / $max)->round(1) : '0';
    }
}
