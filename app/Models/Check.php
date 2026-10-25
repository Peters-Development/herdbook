<?php

namespace App\Models;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Enums\EnvironmentKind;
use App\Enums\ProjectStatus;
use App\Models\Concerns\RecordsActivity;
use App\Observers\CheckObserver;
use App\Services\Checks\DefaultChecks;
use App\Services\Impact\ImpactAnalyzer;
use Database\Factories\CheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One thing to watch: a URL, a certificate, a port, a DNS answer. It belongs
 * to a hostname or to a server. The state and the counters are the engine's
 * (CheckStateMachine); only the configuration is fillable.
 */
#[Fillable([
    'hostname_id', 'server_id', 'type', 'name', 'target', 'settings', 'interval_seconds',
    'failure_threshold', 'success_threshold', 'alert_level', 'enabled',
])]
#[ObservedBy(CheckObserver::class)]
class Check extends Model
{
    /**
     * A check that is not up is tried again within this many seconds, even
     * when its interval is hours (a certificate check): a blip clears fast,
     * a real problem is confirmed fast.
     */
    public const int RETRY_SECONDS = 300;

    /** @use HasFactory<CheckFactory> */
    use HasFactory, RecordsActivity;

    protected $attributes = [
        'interval_seconds' => 60,
        'failure_threshold' => 3,
        'success_threshold' => 2,
        'alert_level' => 'normal',
        'enabled' => true,
        'state' => 'unknown',
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (Check $check): void {
            if (blank($check->name)) {
                $check->name = Str::limit($check->type->getLabel().' '.self::hostOf($check->target), 250);
            }

            // A new or changed check runs at the next dispatch, not after its interval.
            if ($check->isDirty(['type', 'target', 'settings', 'enabled'])) {
                $check->next_check_at = null;
            }
        });
    }

    /**
     * An http(s) URL with a host and a valid port, and without credentials
     * in it (secrets do not go in this app; docs/development.md#no-secrets).
     */
    public static function isValidHttpTarget(string $target): bool
    {
        if (filter_var($target, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($target);

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && filled($parts['host'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535));
    }

    /**
     * Points the check from $old to $new when its target is about that host
     * (not a name that merely contains it, like api.$old).
     */
    public function retarget(string $old, string $new): void
    {
        if (self::hostOf($this->target) !== $old) {
            return;
        }

        $this->update([
            'target' => str_replace($old, $new, $this->target),
            'name' => Str::limit(str_replace($old, $new, $this->name), 250),
        ]);
    }

    /**
     * Follows a changed "Redirects to" of its hostname: an HTTPS check on
     * that name that expected $old expects the new redirect, and its name and
     * alert level follow while they are still the defaults (DefaultChecks).
     * A check set up otherwise by hand is left alone: another redirect, a
     * status that is no redirect, or plain http (which often goes to https
     * on the same name first).
     */
    public function expectRedirect(Hostname $hostname, ?string $old): void
    {
        $new = $hostname->redirects_to;
        $status = $this->setting('expected_status');

        if ($this->type !== CheckType::Http
            || ! str_starts_with(mb_strtolower($this->target), 'https://')
            || self::hostOf($this->target) !== $hostname->fqdn
            || $this->expectedRedirect() !== $old
            || (filled($status) && ((int) $status < 300 || (int) $status > 399))) {
            return;
        }

        $settings = $this->settings ?? [];
        unset($settings['redirects_to']);

        $this->update([
            'settings' => $new === null ? $settings : [...$settings, 'redirects_to' => $new],
            'name' => DefaultChecks::isHttpName($this->name, $hostname->fqdn, $old) ? DefaultChecks::httpName($hostname->fqdn, $new) : $this->name,
            'alert_level' => $this->alert_level === DefaultChecks::httpAlertLevel($hostname->environment, $old)
                ? DefaultChecks::httpAlertLevel($hostname->environment, $new)
                : $this->alert_level,
        ]);
    }

    /**
     * Follows "Only on the local network" of its hostname: an HTTP check of
     * $fqdn (the name it probes now) accepts any certificate while the name
     * is local, and verifies it again when the name no longer is, so a
     * public name never goes unverified unnoticed. Also over http://, which
     * may redirect to https. A check of another name or an address is left
     * alone.
     */
    public function followLocalOnly(Hostname $hostname, string $fqdn): void
    {
        if (! $this->type->isHttp()
            || self::hostOf($this->target) !== $fqdn
            || $this->acceptsAnyCertificate() === $hostname->local_only) {
            return;
        }

        $settings = $this->settings ?? [];
        unset($settings['accept_any_certificate']);

        $this->update(['settings' => $hostname->local_only ? [...$settings, 'accept_any_certificate' => true] : $settings]);
    }

    /**
     * Whether an HTTP check follows redirects to the page: not when it
     * checks a redirect itself ("Must redirect to", or an expected 3xx).
     * Only then is its response time the page's.
     */
    public function followsRedirects(): bool
    {
        $status = $this->setting('expected_status');

        return $this->expectedRedirect() === null && ! (filled($status) && (int) $status >= 300 && (int) $status < 400);
    }

    /**
     * Whether its response time is the page's: an active HTTP check that
     * follows redirects (one of a redirect times only the redirect).
     */
    public function timesThePage(): bool
    {
        return $this->enabled && $this->type->isHttp() && $this->followsRedirects();
    }

    /**
     * "storage-1 · Ping 203.0.113.11": a server check says which server,
     * also one a project only reaches through another. Expects server loaded.
     */
    public function nameWithServer(): string
    {
        return $this->server ? "{$this->server->name} · {$this->name}" : $this->name;
    }

    /**
     * Whether an HTTP check skips verifying the certificate ("Accept any
     * certificate"), for a name with a certificate of a CA of its own.
     */
    public function acceptsAnyCertificate(): bool
    {
        return $this->type->isHttp() && (bool) $this->setting('accept_any_certificate', false);
    }

    /**
     * The host an HTTP check must redirect to ("Must redirect to"); null
     * when the page itself must answer.
     */
    public function expectedRedirect(): ?string
    {
        $host = $this->type === CheckType::Http ? $this->setting('redirects_to') : null;

        return filled($host) ? mb_strtolower(trim((string) $host)) : null;
    }

    /**
     * "app.example.com" from "https://app.example.com/path" or "app.example.com:443".
     */
    public static function hostOf(string $target): string
    {
        $host = parse_url(str_contains($target, '://') ? $target : "//{$target}", PHP_URL_HOST);

        return is_string($host) && $host !== '' ? trim($host, '[]') : $target;
    }

    /** @return BelongsTo<Hostname, $this> */
    public function hostname(): BelongsTo
    {
        return $this->belongsTo(Hostname::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasMany<CheckResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(CheckResult::class);
    }

    /** @return HasMany<CheckStat, $this> */
    public function stats(): HasMany
    {
        return $this->hasMany(CheckStat::class);
    }

    /** @return HasMany<CheckEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CheckEvent::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Seconds until the next run: the interval, or sooner while not up.
     */
    public function effectiveInterval(): int
    {
        return $this->state === CheckState::Up ? $this->interval_seconds : min($this->interval_seconds, self::RETRY_SECONDS);
    }

    /**
     * Active, but without a result for three runs: the worker, the scheduler
     * or the own line has been in trouble, and its state means little.
     */
    public function isStale(): bool
    {
        $since = $this->last_checked_at ?? $this->created_at;

        return $this->enabled && $since !== null && $since->lt(now()->subSeconds(3 * $this->effectiveInterval() + 120));
    }

    /**
     * "acme · HTTP shop.example.com": how a check reads in a notification.
     * Expects hostname.environment.project or server to be loaded.
     */
    public function displayName(): string
    {
        $project = $this->hostname?->environment?->project?->name;

        return $project ? "{$project} · {$this->name}" : $this->name;
    }

    /**
     * Whether an outage counts as production: the hostname points at a
     * production environment, or the server (or a server that needs it)
     * runs one.
     */
    public function watchesProduction(): bool
    {
        if ($this->hostname !== null) {
            return $this->hostname->environment?->kind === EnvironmentKind::Production;
        }

        return $this->productionEnvironments()->isNotEmpty();
    }

    /**
     * The projects an outage of this check affects.
     *
     * @return Collection<int, Project>
     */
    public function affectedProjects(): Collection
    {
        if ($this->hostname !== null) {
            return collect([$this->hostname->environment?->project])->filter()->values();
        }

        return $this->productionEnvironments()->map(fn (Environment $environment): Project => $environment->project)->unique('id')->values();
    }

    /**
     * The servers an outage of this check is about.
     *
     * @return Collection<int, Server>
     */
    public function affectedServers(): Collection
    {
        if ($this->server !== null) {
            return collect([$this->server]);
        }

        return $this->hostname?->environment?->servers()->get()->unique('id')->values() ?? collect();
    }

    /**
     * The production environments on this server and on every server that
     * needs it: a storage server that goes down takes down what mounts it.
     * The same reach as "Affected by an outage" and ProjectHealth.
     *
     * @return Collection<int, Environment>
     */
    private function productionEnvironments(): Collection
    {
        if ($this->server === null) {
            return collect();
        }

        $servers = array_keys(ImpactAnalyzer::chains(ServerDependency::query()->get(['id', 'server_id', 'depends_on_id']), $this->server->id));

        // Not archived projects: their checks are paused, an outage pages nobody about them.
        return Environment::query()
            ->with('project')
            ->where('kind', EnvironmentKind::Production)
            ->whereHas('servers', fn (Builder $query) => $query->whereIn('servers.id', $servers))
            ->whereHas('project', fn (Builder $query) => $query->where('status', '!=', ProjectStatus::Archived))
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    protected function activityContext(): array
    {
        return ['hostname_id' => $this->hostname_id, 'server_id' => $this->server_id, 'label' => $this->name];
    }

    protected function casts(): array
    {
        return [
            'type' => CheckType::class,
            'state' => CheckState::class,
            'alert_level' => AlertLevel::class,
            'settings' => 'array',
            'enabled' => 'boolean',
            'interval_seconds' => 'integer',
            'failure_threshold' => 'integer',
            'success_threshold' => 'integer',
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_state_changed_at' => 'datetime',
            'last_response_ms' => 'integer',
        ];
    }
}
