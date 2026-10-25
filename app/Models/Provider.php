<?php

namespace App\Models;

use App\Enums\ProviderKind;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hosting company, registrar, DNS, mail or internet provider.
 */
#[Fillable(['name', 'kinds', 'website_url', 'panel_url', 'support_email', 'support_phone', 'renewal_lead_days', 'notes'])]
class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory, RecordsActivity;

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @return HasMany<Domain, $this> */
    public function registeredDomains(): HasMany
    {
        return $this->hasMany(Domain::class, 'registrar_id');
    }

    /** @return HasMany<Domain, $this> */
    public function dnsDomains(): HasMany
    {
        return $this->hasMany(Domain::class, 'dns_provider_id');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The networks whose internet line comes from this provider.
     *
     * @return HasMany<Network, $this>
     */
    public function networks(): HasMany
    {
        return $this->hasMany(Network::class);
    }

    /**
     * Providers that offer $kind, plus the one already chosen ($current):
     * unticking a kind on a provider must not make existing records fail
     * validation.
     *
     * @param  Builder<Provider>  $query
     */
    public function scopeOffering(Builder $query, ProviderKind $kind, ?int $current = null): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereJsonContains('kinds', $kind->value)
            ->when($current, fn (Builder $query, int $id) => $query->orWhereKey($id)));
    }

    protected function casts(): array
    {
        return [
            'kinds' => 'array',
            'renewal_lead_days' => 'integer',
        ];
    }
}
