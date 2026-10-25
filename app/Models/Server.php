<?php

namespace App\Models;

use App\Enums\MaintenanceTask;
use App\Enums\ServerStatus;
use App\Models\Concerns\HasCost;
use App\Models\Concerns\HasRenewal;
use App\Models\Concerns\Linkable;
use App\Models\Concerns\RecordsActivity;
use App\Models\Contracts\Renewable;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A VPS or other machine. Its hosting contract renews like a domain does.
 */
#[Fillable([
    'provider_id', 'network_id', 'name', 'provider_hostname', 'status', 'plan', 'location',
    'public_ipv4', 'public_ipv6', 'private_ip', 'tailscale_ip',
    'vcpu', 'ram_gb', 'disk_gb', 'os_name', 'os_version', 'ssh_user', 'ssh_port',
    'backup_policy', 'cost_amount', 'cost_period',
    'expires_on', 'auto_renews', 'renewal_period_months', 'renewal_lead_days', 'renewal_stopped_at',
    'notes',
])]
class Server extends Model implements Renewable
{
    /** @use HasFactory<ServerFactory> */
    use HasCost, HasFactory, HasRenewal, Linkable, RecordsActivity;

    protected $attributes = [
        'status' => 'active',
        'ssh_port' => 22,
    ];

    protected static function booted(): void
    {
        // A server out of use pages nobody: its own checks are paused.
        static::updated(function (Server $server): void {
            if ($server->wasChanged('status') && $server->status === ServerStatus::Decommissioned) {
                $server->checks()->where('enabled', true)->get()->each->update(['enabled' => false]);
            }

            // A changed address moves the checks that probe it: the old one may
            // soon belong to someone else and answer for the wrong machine.
            foreach (['public_ipv4', 'public_ipv6', 'private_ip', 'tailscale_ip'] as $address) {
                $old = $server->getOriginal($address);

                if ($server->wasChanged($address) && filled($old) && filled($server->{$address})) {
                    $server->checks()->get()->each->retarget($old, $server->{$address});
                }
            }
        });
    }

    /** @return HasMany<Check, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public static function renewalProviderRelation(): string
    {
        return 'provider';
    }

    public static function costRelations(): array
    {
        return ['environments.project.client'];
    }

    /**
     * Through the environments it runs.
     */
    public function costProjects(): Collection
    {
        return $this->environments
            ->map(fn (Environment $environment): ?Project => $environment->project)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->toBase();
    }

    /**
     * Where the server stands, such as an office LAN (optional: a VPS
     * has its provider).
     *
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * Rented from a provider, or with an expiry date; not a machine of our
     * own, such as one in an office.
     */
    public function hasContract(): bool
    {
        return $this->provider_id !== null || $this->expires_on !== null || $this->renewal_stopped_at !== null;
    }

    /**
     * hasContract() in a query.
     *
     * @param  Builder<Server>  $query
     */
    public function scopeWithContract(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNotNull('provider_id')
            ->orWhereNotNull('expires_on')
            ->orWhereNotNull('renewal_stopped_at'));
    }

    /** @return HasMany<EnvironmentServer, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(EnvironmentServer::class);
    }

    /** @return BelongsToMany<Environment, $this, EnvironmentServer> */
    public function environments(): BelongsToMany
    {
        return $this->belongsToMany(Environment::class)
            ->using(EnvironmentServer::class)
            ->withPivot(['id', 'role', 'notes']);
    }

    /**
     * What this server needs to work (NFS storage, a WireGuard peer, ...).
     *
     * @return HasMany<ServerDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(ServerDependency::class);
    }

    /**
     * The servers that need this one.
     *
     * @return HasMany<ServerDependency, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(ServerDependency::class, 'depends_on_id');
    }

    /**
     * When each tracked task (Updates, Reboot, ...) was last done on this
     * server: one query, once per instance.
     *
     * @return array<string, ?CarbonImmutable> task value => moment
     */
    public function lastDone(): array
    {
        return once(function (): array {
            $query = self::query()->whereKey($this->getKey())->select('servers.id');

            foreach (MaintenanceTask::tracked() as $task) {
                $query->withLastDone($task);
            }

            $row = $query->first();

            return collect(MaintenanceTask::tracked())
                ->mapWithKeys(fn (MaintenanceTask $task): array => [$task->value => $row?->{"last_{$task->value}_at"}])
                ->all();
        });
    }

    /**
     * The planned maintenance due first (the end of its window, or else its
     * start, as on the dashboard), so one that is overdue comes before an
     * open window; once per instance.
     */
    public function nextMaintenance(): ?Maintenance
    {
        return once(fn (): ?Maintenance => $this->maintenances()->planned()->orderByDue()->first());
    }

    /**
     * Adds "last_{task}_at": when $task was last done on the server, from
     * the maintenance linked to it that is done.
     *
     * @param  Builder<Server>  $query
     */
    public function scopeWithLastDone(Builder $query, MaintenanceTask $task): void
    {
        $column = "last_{$task->value}_at";

        $query
            ->addSelect([$column => Maintenance::query()
                ->done()
                ->select('maintenances.starts_at')
                ->join('maintainables', 'maintainables.maintenance_id', '=', 'maintenances.id')
                ->where('maintainables.maintainable_type', $this->getMorphClass())
                ->whereColumn('maintainables.maintainable_id', 'servers.id')
                ->whereJsonContains('maintenances.tasks', $task->value)
                ->orderByDesc('maintenances.starts_at')
                ->limit(1),
            ])
            ->withCasts([$column => 'datetime']);
    }

    protected function casts(): array
    {
        return [
            'status' => ServerStatus::class,
            'vcpu' => 'integer',
            'ram_gb' => 'integer',
            'disk_gb' => 'integer',
            'ssh_port' => 'integer',
        ];
    }
}
