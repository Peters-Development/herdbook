<?php

namespace App\Models;

use App\Enums\CostPeriod;
use App\Models\Concerns\HasCost;
use App\Models\Concerns\HasRenewal;
use App\Models\Concerns\RecordsActivity;
use App\Models\Contracts\Renewable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * What else is paid for: a licence, mail hosting, a SaaS plan, storage.
 * It renews like a domain does.
 */
#[Fillable([
    'name', 'provider_id', 'plan', 'project_id', 'client_id', 'cost_amount', 'cost_period',
    'expires_on', 'auto_renews', 'renewal_period_months', 'renewal_lead_days', 'renewal_stopped_at',
    'notes',
])]
class Subscription extends Model implements Renewable
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasCost, HasFactory, HasRenewal, RecordsActivity;

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Empty: not for one project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Empty: the client of the project, or ours.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public static function renewalProviderRelation(): string
    {
        return 'provider';
    }

    /**
     * Whose it is: its own client, or else its project's; null: ours.
     */
    public function effectiveClient(): ?Client
    {
        return $this->client ?? $this->project?->client;
    }

    public static function costRelations(): array
    {
        return ['client', 'project.client'];
    }

    public function costProjects(): Collection
    {
        return collect([$this->project])->filter()->values();
    }

    public function costClients(): Collection
    {
        return collect([$this->effectiveClient()]);
    }

    /**
     * A monthly subscription without an expiry date runs on: nothing to
     * renew, so no renewal status.
     */
    public function hasContract(): bool
    {
        return $this->expires_on !== null || $this->renewal_stopped_at !== null || $this->cost_period !== CostPeriod::Month;
    }

    /**
     * hasContract() in a query.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeWithContract(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNotNull('expires_on')
            ->orWhereNotNull('renewal_stopped_at')
            ->orWhereNull('cost_period')
            ->orWhere('cost_period', '!=', CostPeriod::Month->value));
    }
}
