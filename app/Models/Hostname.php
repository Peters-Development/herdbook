<?php

namespace App\Models;

use App\Enums\CheckState;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\HostnameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A name under a domain ("app.example.com") that points at an environment.
 */
#[Fillable(['domain_id', 'environment_id', 'fqdn', 'purpose', 'redirects_to', 'behind_proxy', 'local_only', 'expected_ips', 'notes'])]
class Hostname extends Model
{
    /** @use HasFactory<HostnameFactory> */
    use HasFactory, RecordsActivity;

    /**
     * A name like "app.example.com", for a regex: no scheme, port or path.
     */
    public const string NAME_PATTERN = '(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+';

    protected $attributes = [
        'behind_proxy' => false,
        'local_only' => false,
    ];

    protected static function booted(): void
    {
        // A name under a local domain can only be local.
        static::saving(function (Hostname $hostname): void {
            // Only when it could have changed: Domain::updated() keeps the rest in step.
            if (! $hostname->local_only && $hostname->isDirty(['domain_id', 'local_only']) && Domain::query()->whereKey($hostname->domain_id)->where('local_only', true)->exists()) {
                $hostname->local_only = true;
            }
        });

        // The checks made for this name (DefaultChecks, or by hand with the
        // name in their target) follow what changes on it.
        static::updated(function (Hostname $hostname): void {
            // Its HTTP checks accept any certificate while it is local, and
            // verify it again after. This runs first, while they still probe
            // the old name, because a rename to a wildcard returns early below.
            // Certificate and DNS checks stay: one set up by hand may be meant.
            if ($hostname->wasChanged('local_only')) {
                $hostname->checks()->get()->each->followLocalOnly($hostname, (string) $hostname->getOriginal('fqdn'));
            }

            // A rename: they probe the new name. A wildcard is no name to
            // probe: its checks are paused instead.
            if ($hostname->wasChanged('fqdn')) {
                if (! $hostname->canBeMonitored()) {
                    $hostname->checks()->where('enabled', true)->get()->each->update(['enabled' => false]);

                    return;
                }

                $hostname->checks()->get()->each->retarget((string) $hostname->getOriginal('fqdn'), $hostname->fqdn);
            }

            // Its HTTPS checks that expected the old redirect expect the new one.
            if ($hostname->wasChanged('redirects_to')) {
                $hostname->checks()->get()->each->expectRedirect($hostname, $hostname->getOriginal('redirects_to'));
            }
        });
    }

    public function canBeMonitored(): bool
    {
        return ! str_starts_with($this->fqdn, '*.');
    }

    /**
     * The worst state of its active checks; null without any.
     * Expects `checks` to be loaded on lists.
     */
    public function monitoringState(): ?CheckState
    {
        $states = $this->checks->where('enabled', true)->pluck('state');

        foreach ([CheckState::Down, CheckState::Pending, CheckState::Unknown, CheckState::Up] as $state) {
            if ($states->contains($state)) {
                return $state;
            }
        }

        return null;
    }

    /** @return HasMany<Check, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * The hostname it sends visitors to, when that name is in Herdbook too.
     *
     * @return BelongsTo<Hostname, $this>
     */
    public function redirectTarget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redirects_to', 'fqdn');
    }

    /**
     * Whether $fqdn is $domain itself or a name under it.
     */
    public static function belongsToDomain(string $fqdn, string $domain): bool
    {
        $fqdn = mb_strtolower(trim($fqdn));
        $domain = mb_strtolower(trim($domain));

        return $fqdn === $domain || str_ends_with($fqdn, '.'.$domain);
    }

    /** @return Attribute<string, string> */
    protected function fqdn(): Attribute
    {
        return Attribute::set(fn (string $value): string => mb_strtolower(trim($value)));
    }

    /** @return Attribute<?string, ?string> */
    protected function redirectsTo(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => filled($value) ? mb_strtolower(trim($value)) : null);
    }

    /** @return array<string, mixed> */
    protected function activityContext(): array
    {
        return ['domain_id' => $this->domain_id, 'label' => $this->fqdn];
    }

    protected function casts(): array
    {
        return [
            'behind_proxy' => 'boolean',
            'local_only' => 'boolean',
            'expected_ips' => 'array',
        ];
    }
}
