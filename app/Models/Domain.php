<?php

namespace App\Models;

use App\Enums\CostPeriod;
use App\Models\Concerns\HasCost;
use App\Models\Concerns\HasRenewal;
use App\Models\Concerns\Linkable;
use App\Models\Concerns\RecordsActivity;
use App\Models\Contracts\Renewable;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A registered domain ("example.com"). Its expiry date is entered by hand:
 * .nl and .eu publish none. Or one in the local DNS only ("home.arpa"),
 * registered nowhere: no registrar, costs or renewal.
 */
#[Fillable([
    'name', 'client_id', 'registrar_id', 'dns_provider_id', 'mail_server_id', 'dns_server_id', 'local_only', 'cost_amount', 'cost_period',
    'expires_on', 'auto_renews', 'renewal_period_months', 'renewal_lead_days', 'renewal_stopped_at',
    'notes',
])]
class Domain extends Model implements Renewable
{
    /** @use HasFactory<DomainFactory> */
    use HasCost, HasFactory, HasRenewal, Linkable, RecordsActivity {
        HasCost::yearlyCostSql as ownYearlyCostSql;
    }

    /**
     * A registered name: two labels or more ("example.com"). A local one may
     * be a single label ("internal").
     */
    public const string NAME_PATTERN = '/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i';

    public const string LOCAL_NAME_PATTERN = '/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)*$/i';

    protected $attributes = [
        'local_only' => false,
    ];

    protected static function booted(): void
    {
        // A local domain has nothing to renew or pay: what was filled in
        // before would still count in the costs and renewals.
        static::saving(function (Domain $domain): void {
            if ($domain->local_only) {
                $domain->fill(['registrar_id' => null, 'cost_amount' => null, 'cost_period' => null, 'expires_on' => null, 'renewal_stopped_at' => null]);
            }

            // With a DNS server, the DNS host is that server's provider (none
            // for a box of your own), so the lists, counts and filters on the
            // DNS host need only the one column. A query, not the relation: a
            // loaded one keeps the old server. Without a DNS server the DNS
            // host is chosen by hand, also when one was just cleared.
            if ($domain->dns_server_id !== null && $domain->isDirty(['dns_server_id', 'dns_provider_id'])) {
                $domain->dns_provider_id = Server::query()->whereKey($domain->dns_server_id)->value('provider_id');
            }
        });

        // Every name under a local domain is local (Hostname::booted()), and
        // back: one mistaken toggle is undone by the next. Through Eloquent,
        // so their HTTP checks follow.
        static::updated(function (Domain $domain): void {
            if ($domain->wasChanged('local_only')) {
                $domain->hostnames()->where('local_only', ! $domain->local_only)->get()->each->update(['local_only' => $domain->local_only]);
            }
        });

        // Through Eloquent, so each removed hostname is logged on the domain.
        static::deleting(function (Domain $domain): void {
            $domain->hostnames()->get()->each->delete();
        });
    }

    /**
     * The hostnames go through Eloquent (see booted()), so all of it is one
     * transaction.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    /**
     * Empty: the domain is ours.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Provider, $this> */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'registrar_id');
    }

    /** @return BelongsTo<Provider, $this> */
    public function dnsProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'dns_provider_id');
    }

    /**
     * Where its mail runs, when that is not on a server of an environment of
     * its own (a hosting package for mail and DNS): an outage there reaches
     * the environments of its hostnames (ImpactAnalyzer::usage()).
     *
     * @return BelongsTo<Server, $this>
     */
    public function mailServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'mail_server_id');
    }

    /**
     * Where its DNS runs, when that is a server of its own (a hosting package
     * for mail and DNS, a nameserver): an outage there reaches the
     * environments of its hostnames (ImpactAnalyzer::usage()). Its provider
     * is the DNS host (booted()).
     *
     * @return BelongsTo<Server, $this>
     */
    public function dnsServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'dns_server_id');
    }

    /**
     * Who serves its DNS, to show: the DNS host, or the DNS server itself
     * when that has no provider (a nameserver of your own).
     */
    public function dnsHost(): Provider|Server|null
    {
        return $this->dnsProvider ?? $this->dnsServer;
    }

    public function dnsHostName(): ?string
    {
        return $this->dnsHost()?->name;
    }

    public static function renewalProviderRelation(): string
    {
        return 'registrar';
    }

    public static function costRelations(): array
    {
        return ['client', 'hostnames.environment.project.client'];
    }

    /**
     * Through the environments of its hostnames.
     */
    public function costProjects(): Collection
    {
        return $this->hostnames
            ->map(fn (Hostname $hostname): ?Project => $hostname->environment?->project)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->toBase();
    }

    public function costClients(): Collection
    {
        return collect([$this->client]);
    }

    /**
     * Registered somewhere, unless it is a domain of the local DNS only.
     */
    public function hasContract(): bool
    {
        return ! $this->local_only;
    }

    /**
     * A local domain is paid for nowhere: an amount set on it is cleared
     * (booted()).
     */
    public function canHaveCost(): bool
    {
        return ! $this->local_only;
    }

    /**
     * Everything after its first label: a domain is a registered name, one
     * label under its extension ("example.co.uk": "co.uk"). A name without a
     * dot (a local one) is its own extension, as in extensionSql().
     */
    public static function extensionOf(string $name): string
    {
        return Str::after($name, '.');
    }

    /**
     * extensionOf() in SQL: the same on MySQL and SQLite. For a query on the
     * domains table itself (not aliased).
     */
    public static function extensionSql(): string
    {
        return "substr(domains.name, instr(domains.name, '.') + 1)";
    }

    /**
     * The line of its registrar's price list for its extension, when it can
     * cost anything. Reads the registrar's prices once loaded
     * (costAmountRelations()).
     */
    public function listPrice(): ?DomainPrice
    {
        if (! $this->canHaveCost() || $this->registrar_id === null || $this->name === null) {
            return null;
        }

        // A registrar loaded before the registrar changed (a form) is not this one.
        if ($this->relationLoaded('registrar') && (int) $this->registrar?->getKey() !== (int) $this->registrar_id) {
            $this->unsetRelation('registrar');
        }

        $extension = self::extensionOf($this->name);

        // Strictly: "10" is no "1e1".
        return $this->registrar?->domainPrices->first(fn (DomainPrice $price): bool => $price->extension === $extension);
    }

    /**
     * Its own amount, or else the price list of its registrar (per year).
     * An amount without a period is its own still, and missing.
     */
    public function costAmount(): ?string
    {
        return $this->cost_amount ?? $this->listPrice()?->cost_amount;
    }

    public function costPeriod(): ?CostPeriod
    {
        if ($this->cost_amount !== null) {
            return $this->cost_period;
        }

        return $this->listPrice() === null ? null : CostPeriod::Year;
    }

    /**
     * Whether what it costs comes from the price list of its registrar.
     */
    public function usesPriceList(): bool
    {
        return $this->cost_amount === null && $this->listPrice() !== null;
    }

    /**
     * An amount of its own per year that is just its list price: it would
     * not follow a price change.
     */
    public function isAtListPrice(): bool
    {
        return $this->cost_amount !== null
            && $this->cost_period === CostPeriod::Year
            && $this->cost_amount === $this->listPrice()?->cost_amount;
    }

    /**
     * Its own amount goes: the price list counts. Through Eloquent, so it is
     * logged.
     */
    public function usePriceList(): void
    {
        $this->update(['cost_amount' => null, 'cost_period' => null]);
    }

    public function costSource(): ?string
    {
        return $this->usesPriceList() ? __('From the price list of :registrar', ['registrar' => $this->registrar->name]) : null;
    }

    public static function costAmountRelations(): array
    {
        return ['registrar.domainPrices'];
    }

    /**
     * Its own amount per year, or else the price for it in its registrar's
     * list.
     */
    public static function yearlyCostSql(Builder $query): array
    {
        [$own, $bindings] = self::ownYearlyCostSql($query);

        return ["coalesce({$own}, (".self::listPriceSql().'))', $bindings];
    }

    /**
     * The price of its registrar for its extension, as a subquery.
     */
    private static function listPriceSql(): string
    {
        return 'select domain_prices.cost_amount from domain_prices where domains.local_only = 0 and domain_prices.provider_id = domains.registrar_id and domain_prices.extension = '.self::extensionSql();
    }

    /**
     * usesPriceList() in a query.
     *
     * @param  Builder<Domain>  $query
     */
    public function scopeUsingPriceList(Builder $query): void
    {
        $query->whereNull('cost_amount')->whereRaw('exists ('.self::listPriceSql().')');
    }

    /**
     * An own amount without a period, or no own amount and no price for it
     * in its registrar's list.
     */
    protected function whereNoAmount(Builder $query): void
    {
        $query
            ->where(fn (Builder $query) => $query->whereNotNull('cost_amount')->whereNull('cost_period'))
            ->orWhere(fn (Builder $query) => $query->whereNull('cost_amount')->whereRaw('not exists ('.self::listPriceSql().')'));
    }

    /**
     * hasContract() in a query.
     *
     * @param  Builder<Domain>  $query
     */
    public function scopeWithContract(Builder $query): void
    {
        $query->where('local_only', false);
    }

    /** @return HasMany<Hostname, $this> */
    public function hostnames(): HasMany
    {
        return $this->hasMany(Hostname::class);
    }

    /** @return Attribute<string, string> */
    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value): string => mb_strtolower(trim($value)));
    }

    protected function casts(): array
    {
        return [
            'local_only' => 'boolean',
        ];
    }
}
