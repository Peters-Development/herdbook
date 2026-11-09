<?php

namespace App\Models;

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
    use HasCost, HasFactory, HasRenewal, Linkable, RecordsActivity;

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
