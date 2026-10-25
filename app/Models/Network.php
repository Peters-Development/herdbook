<?php

namespace App\Models;

use App\Models\Concerns\HasCost;
use App\Models\Concerns\HasRenewal;
use App\Models\Concerns\Linkable;
use App\Models\Concerns\RecordsActivity;
use App\Models\Contracts\Renewable;
use App\Rules\Subnet;
use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Where servers of our own stand, such as an office LAN, for
 * reference. A server in it gets its line on the map from the network, as
 * a VPS gets one from its provider; a server is in one network at most.
 * The contract of its internet line renews like a hosting contract.
 */
#[Fillable([
    'name', 'provider_id', 'location', 'subnet', 'gateway', 'public_ip', 'cost_amount', 'cost_period',
    'expires_on', 'auto_renews', 'renewal_period_months', 'renewal_lead_days', 'renewal_stopped_at',
    'notes',
])]
class Network extends Model implements Renewable
{
    /** @use HasFactory<NetworkFactory> */
    use HasCost, HasFactory, HasRenewal, Linkable, RecordsActivity;

    protected static function booted(): void
    {
        // Through Eloquent, so it is logged: the addresses on the network's
        // page, the servers that leave it on theirs.
        static::deleting(function (Network $network): void {
            $network->addresses()->get()->each->delete();
            $network->servers()->get()->each->update(['network_id' => null]);
        });
    }

    /**
     * The addresses and servers go through Eloquent (see booted()), so all
     * of it is one transaction.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    /**
     * The internet provider of the line.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public static function renewalProviderRelation(): string
    {
        return 'provider';
    }

    /**
     * With an internet provider, or with an expiry date: a LAN without a
     * line of its own has no contract.
     */
    public function hasContract(): bool
    {
        return $this->provider_id !== null || $this->expires_on !== null || $this->renewal_stopped_at !== null;
    }

    /**
     * hasContract() in a query.
     *
     * @param  Builder<Network>  $query
     */
    public function scopeWithContract(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNotNull('provider_id')
            ->orWhereNotNull('expires_on')
            ->orWhereNotNull('renewal_stopped_at'));
    }

    public static function costRelations(): array
    {
        return ['servers.environments.project.client'];
    }

    /**
     * Through the environments its servers run.
     */
    public function costProjects(): Collection
    {
        return $this->servers
            ->flatMap(fn (Server $server): Collection => $server->costProjects())
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * The other devices on the network: router, NAS, printer, ...
     *
     * @return HasMany<NetworkAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(NetworkAddress::class);
    }

    /**
     * Whether $address falls within the subnet; null without a subnet to
     * compare with.
     */
    public function contains(string $address): ?bool
    {
        if ($this->subnet === null || ! Subnet::isValid($this->subnet)) {
            return null;
        }

        return IpUtils::checkIp($address, $this->subnet);
    }

    /**
     * The servers and the other devices together, in the order of their
     * address; a server without a private address at the end. "outside":
     * not in the subnet; "duplicate": another entry has the same address
     * (a server and a device, which the forms cannot see from each other).
     *
     * @return Collection<int, array{address: ?string, name: string, mac_address: ?string, notes: ?string, server: ?Server, outside: bool, duplicate: bool}>
     */
    public function addressBook(): Collection
    {
        $servers = $this->servers()->orderBy('name')->get()->map(fn (Server $server): array => [
            'address' => $server->private_ip,
            'name' => $server->name,
            'mac_address' => null,
            'notes' => null,
            'server' => $server,
        ]);

        $addresses = $this->addresses()->get()->map(fn (NetworkAddress $address): array => [
            'address' => $address->address,
            'name' => $address->name,
            'mac_address' => $address->mac_address,
            'notes' => $address->notes,
            'server' => null,
        ]);

        $entries = $servers->concat($addresses);
        $taken = $entries->pluck('address')->filter()->map(NetworkAddress::sortKey(...))->countBy();

        return $entries
            ->map(fn (array $entry): array => [
                ...$entry,
                'outside' => $entry['address'] !== null && $this->contains($entry['address']) === false,
                'duplicate' => $entry['address'] !== null && $taken[NetworkAddress::sortKey($entry['address'])] > 1,
            ])
            ->sortBy(fn (array $entry): string => $entry['address'] === null ? "\u{FFFF}" : NetworkAddress::sortKey($entry['address']))
            ->values();
    }

    /**
     * "192.168.60.7/24" is stored as "192.168.60.0/24".
     *
     * @return Attribute<?string, ?string>
     */
    protected function subnet(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => blank($value) ? null : Subnet::normalize(trim($value)));
    }
}
