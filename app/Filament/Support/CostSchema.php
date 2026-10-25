<?php

namespace App\Filament\Support;

use App\Enums\CostPeriod;
use App\Enums\RenewalPeriod;
use App\Filament\Pages\Costs;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Client;
use App\Models\Contracts\Renewable;
use App\Models\Project;
use App\Services\Costs\CostLine;
use App\Services\Costs\Money;
use App\Services\Costs\RecurringCosts;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The form, infolist, column, filter and bulk action for what something
 * costs (HasCost: domains, servers, subscriptions, networks), and the cost
 * section of a project or client. Amounts exclude VAT.
 */
final class CostSchema
{
    public static function formSection(CostPeriod $default): Section
    {
        [$amount, $period] = self::fields($default);

        return Section::make(__('Costs'))
            ->description(__('Excluding VAT. A monthly amount counts every month, a yearly amount at every renewal.'))
            ->columns(2)
            ->schema([
                $amount,
                // A monthly amount usually renews monthly: the term follows, unless it was changed.
                $period->live()->afterStateUpdated(function (Get $get, Set $set, CostPeriod|string|null $state): void {
                    $term = (int) $get('renewal_period_months');
                    $period = $state instanceof CostPeriod ? $state : CostPeriod::tryFrom((string) $state);

                    if ($period !== null && in_array($term, [RenewalPeriod::OneMonth->value, RenewalPeriod::OneYear->value], true)) {
                        $set('renewal_period_months', $period === CostPeriod::Month ? RenewalPeriod::OneMonth->value : RenewalPeriod::OneYear->value);
                    }
                }),
            ]);
    }

    public static function entry(): TextEntry
    {
        return TextEntry::make('cost')
            ->label(__('Costs'))
            ->state(fn (Renewable $record): ?string => Money::describe($record))
            ->helperText(fn (Renewable $record): ?string => $record->hasCost() && $record->cost_period === CostPeriod::Month
                ? __(':amount per year', ['amount' => Money::format($record->yearlyCost())])
                : null)
            ->placeholder('—');
    }

    /**
     * "Costs" on the page of a project or a client: what it costs per year
     * (RecurringCosts), of what, what it shares with others, and what has
     * no amount yet.
     */
    public static function ownerSection(): Section
    {
        return Section::make(__('Costs'))
            ->description(__('Per year, excluding VAT. Cancelled items are not counted.'))
            ->collapsible()
            ->afterHeader([
                Action::make('addSubscription')
                    ->label(__('Add subscription'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->link()
                    ->url(fn (Project|Client $record): string => SubscriptionResource::getUrl('create', [
                        ($record instanceof Project ? SubscriptionForm::PROJECT_PARAMETER : SubscriptionForm::CLIENT_PARAMETER) => $record->getKey(),
                    ])),
                Action::make('allCosts')
                    ->label(__('All costs'))
                    ->icon(Heroicon::OutlinedCurrencyEuro)
                    ->link()
                    ->url(Costs::getUrl()),
            ])
            ->schema([
                ViewEntry::make('costs')
                    ->label(__('Costs'))
                    ->hiddenLabel()
                    ->view('filament.infolists.costs')
                    ->state(fn (Project|Client $record): array => self::summary($record)),
            ]);
    }

    /**
     * An item as the cost page and the cost section show it: icon, name and
     * provider.
     *
     * @return array{name: string, mono: bool, url: string, icon: string, provider: ?string}
     */
    public static function describe(Model&Renewable $item): array
    {
        return [
            'name' => $item->name,
            'mono' => RenewalSchema::mono($item),
            'url' => RenewalSchema::url($item),
            'icon' => RenewalSchema::icon($item),
            'provider' => $item->renewalProvider()?->name,
        ];
    }

    /**
     * An item with its yearly amount, and a monthly amount as it was entered.
     *
     * @return array<string, mixed>
     */
    public static function line(CostLine $line): array
    {
        return [
            ...self::describe($line->item),
            'yearly' => Money::format($line->yearly),
            'monthly' => $line->item->cost_period === CostPeriod::Month ? Money::describe($line->item) : null,
            'detail' => $line->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(Project|Client $owner): array
    {
        $overview = app(RecurringCosts::class)->overview();
        $group = $overview->groupFor($owner);
        $unpriced = $overview->unpricedFor($owner);

        $nothingOwn = $group->total->compare(0) === 0;

        return [
            'owner' => $owner instanceof Project ? __('this project') : __('this client'),
            // Only shared costs: it costs something, just not on its own (as on the cost page).
            'yearly' => $nothingOwn && $group->shared !== [] ? __('shared only') : Money::format($group->total),
            'perMonth' => $nothingOwn ? null : Money::format($group->total / 12),
            'shared' => $group->shared === [] ? null : Money::format($group->sharedTotal()),
            'items' => array_map(self::line(...), $group->items),
            'sharedItems' => array_map(self::line(...), $group->shared),
            'unpriced' => array_map(self::describe(...), $unpriced),
        ];
    }

    /**
     * Sorts by what it costs per year, so a month and a year compare.
     */
    public static function column(): TextColumn
    {
        return TextColumn::make('cost_amount')
            ->label(__('Costs'))
            ->state(fn (Renewable $record): ?string => Money::describe($record))
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                "{$query->qualifyColumn('cost_amount')} * (case when {$query->qualifyColumn('cost_period')} = ? then 12 else 1 end) ".($direction === 'desc' ? 'desc' : 'asc'),
                [CostPeriod::Month->value],
            ))
            ->placeholder('—');
    }

    public static function missingFilter(): Filter
    {
        return Filter::make('missing_cost')
            ->label(__('Without an amount'))
            ->query(fn (Builder $query): Builder => $query->missingCost());
    }

    /**
     * Many domains at one registrar cost the same: one amount for the
     * selection. Through Eloquent, so each change is logged.
     */
    public static function bulkAction(CostPeriod $default): BulkAction
    {
        return BulkAction::make('setCost')
            ->label(__('Set amount'))
            ->icon(Heroicon::OutlinedCurrencyEuro)
            ->modalHeading(__('Set amount'))
            ->modalDescription(__('For everything you selected, excluding VAT.'))
            ->modalSubmitActionLabel(__('Set'))
            ->schema(array_map(fn (TextInput|Select $field): TextInput|Select => $field->required(), self::fields($default)))
            ->action(function (Collection $records, array $data): void {
                if ($records->isEmpty()) {
                    return;
                }

                // A local domain costs nothing: it would clear the amount again.
                $priced = $records->filter->canHaveCost();
                $priced->each->update(['cost_amount' => $data['cost_amount'], 'cost_period' => $data['cost_period']]);
                $skipped = $records->count() - $priced->count();
                $skippedText = trans_choice(':count local domain skipped: it costs nothing.|:count local domains skipped: they cost nothing.', $skipped);

                if ($priced->isEmpty()) {
                    Notification::make()->warning()->title($skippedText)->send();

                    return;
                }

                $resource = RenewalSchema::resource($priced->first());

                Notification::make()
                    ->success()
                    ->title(__('Amount set for :count :items: :amount', [
                        'count' => $priced->count(),
                        'items' => $priced->count() === 1 ? $resource::getModelLabel() : $resource::getPluralModelLabel(),
                        'amount' => Money::describe($priced->first()),
                    ]))
                    ->body($skipped > 0 ? $skippedText : null)
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @return list<TextInput|Select>
     */
    private static function fields(CostPeriod $default): array
    {
        return [
            TextInput::make('cost_amount')
                ->label(__('Amount'))
                ->numeric()
                ->minValue(0)
                ->maxValue(99999999.99)
                ->prefix(config('herdbook.currency_symbol')),
            Select::make('cost_period')
                ->label(__('Billing period'))
                ->options(CostPeriod::class)
                ->default($default)
                ->requiredWith('cost_amount')
                // No amount, no period: the default would otherwise be stored on its own.
                ->dehydrateStateUsing(fn (CostPeriod|string|null $state, Get $get): CostPeriod|string|null => filled($get('cost_amount')) ? $state : null),
        ];
    }
}
