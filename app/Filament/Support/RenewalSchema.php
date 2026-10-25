<?php

namespace App\Filament\Support;

use App\Enums\RenewalPeriod;
use App\Enums\RenewalStatus;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Contracts\Renewable;
use App\Models\Domain;
use App\Models\Network;
use App\Models\Server;
use App\Models\Subscription;
use App\Services\RenewalAttention;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * The form, infolist, column and actions for everything that renews
 * (Renewable: domains, hosting contracts, subscriptions, internet lines).
 */
final class RenewalSchema
{
    /**
     * @param  ?string  $heading  null: "Renewal"
     * @param  int  $term  the term a new item starts with, in months
     */
    public static function formSection(?string $heading = null, ?string $description = null, int $term = 12): Section
    {
        return Section::make($heading ?? __('Renewal'))
            ->description(__('You enter the expiry date yourself. "Renew" moves it on by one term.').($description ? " {$description}" : ''))
            ->columns(2)
            ->schema([
                DatePicker::make('expires_on')
                    ->label(__('Expiry date'))
                    ->native(false)
                    ->displayFormat(__('formats.date')),
                Select::make('renewal_period_months')
                    ->label(__('Term'))
                    // Plain options, not the enum: the column is an integer (HasRenewal).
                    ->options(collect(RenewalPeriod::cases())->mapWithKeys(fn (RenewalPeriod $period): array => [$period->value => $period->getLabel()])->all())
                    ->default($term)
                    ->required(),
                Toggle::make('auto_renews')
                    ->label(__('Renews automatically'))
                    ->default(true)
                    ->inline(false),
                TextInput::make('renewal_lead_days')
                    ->label(__('Renewal lead time (days)'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(365)
                    ->placeholder(__('From the provider'))
                    ->helperText(__("Default: the provider's lead time, or :days days.", ['days' => config('herdbook.renewal.default_lead_days')])),
            ]);
    }

    /**
     * @param  ?string  $heading  null: "Renewal"
     */
    public static function infolistSection(?string $heading = null): Section
    {
        return Section::make($heading ?? __('Renewal'))
            ->visible(fn (Renewable $record): bool => $record->hasContract())
            ->columns(3)
            ->schema([
                TextEntry::make('renewal_status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Renewable $record): RenewalStatus => $record->renewalStatus()),
                TextEntry::make('expires_on')
                    ->label(__('Expiry date'))
                    ->date(__('formats.date'))
                    ->placeholder(__('Not filled in')),
                TextEntry::make('renewal_terms')
                    ->label(__('Terms'))
                    ->state(fn (Renewable $record): string => self::describeTerms($record)),
            ]);
    }

    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('renewal_status')
            ->label(__('Renewal'))
            ->badge()
            ->state(fn (Renewable $record): ?RenewalStatus => $record->hasContract() ? $record->renewalStatus() : null)
            ->placeholder('—');
    }

    /**
     * The status is computed, not stored: the filter computes it for every
     * row of $model (a few hundred at most) and narrows the query to the ids.
     *
     * @param  class-string<Model&Renewable>  $model
     */
    public static function statusFilter(string $model): SelectFilter
    {
        return SelectFilter::make('renewal_status')
            ->label(__('Renewal'))
            ->options(RenewalStatus::class)
            ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                ? $query
                : $query->whereKey(self::all($model)
                    ->filter(fn (Renewable $record): bool => $record->hasContract() && $record->renewalStatus()->value === $data['value'])
                    ->modelKeys()));
    }

    /**
     * @param  class-string<Model&Renewable>  $model
     */
    public static function attentionFilter(string $model): Filter
    {
        return Filter::make('needs_attention')
            ->label(__('Requires attention'))
            ->query(fn (Builder $query): Builder => $query->whereKey(app(RenewalAttention::class)->for($model)->modelKeys()));
    }

    /**
     * The resource of the item's kind.
     *
     * @param  Renewable|class-string<Model&Renewable>  $item
     * @return class-string<DomainResource|ServerResource|SubscriptionResource|NetworkResource>
     */
    public static function resource(Renewable|string $item): string
    {
        return match (is_string($item) ? $item : $item::class) {
            Domain::class => DomainResource::class,
            Server::class => ServerResource::class,
            Subscription::class => SubscriptionResource::class,
            Network::class => NetworkResource::class,
        };
    }

    public static function url(Renewable $item): string
    {
        return self::resource($item)::getUrl('view', ['record' => $item]);
    }

    public static function icon(Renewable $item): string
    {
        return match ($item::class) {
            Domain::class => 'heroicon-m-globe-alt',
            Server::class => 'heroicon-m-server',
            Subscription::class => 'heroicon-m-credit-card',
            Network::class => 'heroicon-m-wifi',
        };
    }

    /**
     * Domain and server names are technical values (mono); the name of a
     * subscription or a network ("Office") is not.
     */
    public static function mono(Renewable $item): bool
    {
        return $item instanceof Domain || $item instanceof Server;
    }

    public static function renewAction(): Action
    {
        return Action::make('renew')
            ->label(__('Renew'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->visible(fn (Renewable $record): bool => $record->canBeRenewed())
            ->requiresConfirmation()
            ->modalHeading(fn (Renewable $record): string => __('Renew :name', ['name' => $record->name]))
            ->modalDescription(fn (Renewable $record): string => self::describeRenewal($record))
            ->modalSubmitActionLabel(__('Renew'))
            ->action(function (Renewable $record): void {
                $record->renew();

                Notification::make()
                    ->success()
                    ->title(__('Renewed until :date', ['date' => $record->expires_on->translatedFormat(__('formats.date'))]))
                    ->send();
            });
    }

    public static function renewBulkAction(): BulkAction
    {
        return BulkAction::make('renew')
            ->label(__('Renew'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Renew selected items'))
            ->modalDescription(function (Collection $records): HtmlString {
                $lines = $records->filter->canBeRenewed()
                    ->map(fn (Renewable $record): string => e("{$record->name}: ".self::describeRenewal($record)));
                $skipped = $records->reject->canBeRenewed()->count();

                if ($skipped > 0) {
                    $lines->push(e(__(':count skipped (no expiry date, or cancelled).', ['count' => $skipped])));
                }

                return new HtmlString($lines->implode('<br>'));
            })
            ->modalSubmitActionLabel(__('Renew'))
            ->action(function (Collection $records): void {
                $renewable = $records->filter->canBeRenewed();
                $renewable->each->renew();

                Notification::make()
                    ->success()
                    ->title(trans_choice(':count item renewed|:count items renewed', $renewable->count()))
                    ->body($records->count() > $renewable->count()
                        ? __(':count skipped: no expiry date, or cancelled.', ['count' => $records->count() - $renewable->count()])
                        : null)
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function stopAction(): Action
    {
        return Action::make('stopRenewal')
            ->label(__('Stop renewing'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->visible(fn (Renewable $record): bool => $record->renewal_stopped_at === null && $record->hasContract())
            ->requiresConfirmation()
            ->modalDescription(__('You will get no more reminders. You can undo this later.'))
            ->action(fn (Renewable $record) => $record->stopRenewal());
    }

    public static function resumeAction(): Action
    {
        return Action::make('resumeRenewal')
            ->label(__('Resume renewing'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Renewable $record): bool => $record->renewal_stopped_at !== null)
            ->action(fn (Renewable $record) => $record->resumeRenewal());
    }

    /**
     * @param  class-string<Model&Renewable>  $model
     * @return EloquentCollection<int, Model&Renewable>
     */
    private static function all(string $model): EloquentCollection
    {
        return $model::query()->with($model::renewalProviderRelation())->get();
    }

    private static function describeRenewal(Renewable $record): string
    {
        return $record->expires_on->translatedFormat(__('formats.date')).' → '.$record->renewedExpiryDate()->translatedFormat(__('formats.date'));
    }

    private static function describeTerms(Renewable $record): string
    {
        if ($record->renewal_stopped_at !== null) {
            return __('Cancelled on :date', ['date' => $record->renewal_stopped_at->timezone(config('herdbook.display_timezone'))->translatedFormat(__('formats.date'))]);
        }

        $term = $record->renewalTerm();

        return $record->auto_renews
            ? trans_choice('Automatically, :count day before expiry, :term at a time|Automatically, :count days before expiry, :term at a time', $record->effectiveRenewalLeadDays(), ['term' => $term])
            : __('Manually, :term at a time', ['term' => $term]);
    }
}
