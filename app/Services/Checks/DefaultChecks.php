<?php

namespace App\Services\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckType;
use App\Enums\EnvironmentKind;
use App\Models\Environment;
use App\Models\Hostname;
use Illuminate\Support\Str;

/**
 * "Turn on monitoring" on a hostname: the site over HTTPS (or, for a name
 * that only redirects, the redirect), its certificate, and (unless it sits
 * behind a proxy) whether DNS points at the right servers. A name on the
 * local network gets the HTTPS check only. Types the hostname already has
 * are left alone.
 */
final class DefaultChecks
{
    private const int NAME_LIMIT = 250;

    /**
     * @return int how many checks were created
     */
    public function createFor(Hostname $hostname): int
    {
        // "*.example.com" is a pattern, not a name anything can answer on.
        if (! $hostname->canBeMonitored()) {
            return 0;
        }

        $existing = $hostname->checks()->pluck('type')->map(fn (CheckType|string $type): string => $type instanceof CheckType ? $type->value : $type)->all();
        $created = 0;

        foreach ($this->definitions($hostname) as $definition) {
            if (in_array($definition['type']->value, $existing, true)) {
                continue;
            }

            $hostname->checks()->create([
                ...$definition,
                'name' => Str::limit($definition['name'], self::NAME_LIMIT),
                'interval_seconds' => $definition['type']->defaultInterval(),
                'failure_threshold' => $definition['type']->defaultFailureThreshold(),
                'success_threshold' => $definition['type']->defaultSuccessThreshold(),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * The name of a hostname's HTTP check: "HTTP app.example.com", or
     * "Redirect www.example.com" for a name that only redirects. In the
     * active language: it is stored as the check's name.
     */
    public static function httpName(string $fqdn, ?string $redirectsTo, ?string $locale = null): string
    {
        return Str::limit($redirectsTo === null ? "HTTP {$fqdn}" : __('Redirect :host', ['host' => $fqdn], $locale), self::NAME_LIMIT);
    }

    /**
     * Whether $name is still the default name, in any language Herdbook
     * speaks: a check named while someone used Dutch keeps following its
     * redirect when someone using English changes it.
     */
    public static function isHttpName(string $name, string $fqdn, ?string $redirectsTo): bool
    {
        foreach (array_keys(config('herdbook.locales')) as $locale) {
            if ($name === self::httpName($fqdn, $redirectsTo, $locale)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An outage of production wakes someone; the rest can wait. A redirect's
     * site has its own check: a server that goes down pages once, not once
     * per name.
     */
    public static function httpAlertLevel(?Environment $environment, ?string $redirectsTo): AlertLevel
    {
        return $environment?->kind === EnvironmentKind::Production && $redirectsTo === null ? AlertLevel::Critical : AlertLevel::Normal;
    }

    /**
     * @return list<array{type: CheckType, name: string, target: string, alert_level: AlertLevel, settings?: ?array<string, string|bool>}>
     */
    private function definitions(Hostname $hostname): array
    {
        $redirect = $hostname->redirects_to;
        $settings = array_filter([
            'redirects_to' => $redirect,
            'accept_any_certificate' => $hostname->local_only,
        ]);

        $definitions = [
            [
                'type' => CheckType::Http,
                'name' => self::httpName($hostname->fqdn, $redirect),
                'target' => "https://{$hostname->fqdn}/",
                'settings' => $settings === [] ? null : $settings,
                'alert_level' => self::httpAlertLevel($hostname->environment, $redirect),
            ],
        ];

        // A local name's certificate is trusted by the devices, not by the
        // host, and has nothing worth checking: Caddy's own CA issues
        // short-lived ones (twelve hours) and renews them itself. The public
        // resolver a DNS check asks does not know the name.
        if ($hostname->local_only) {
            return $definitions;
        }

        $definitions[] = [
            'type' => CheckType::Tls,
            'name' => __('Certificate :host', ['host' => $hostname->fqdn]),
            'target' => "{$hostname->fqdn}:443",
            'alert_level' => AlertLevel::Normal,
        ];

        if (! $hostname->behind_proxy) {
            $definitions[] = [
                'type' => CheckType::Dns,
                'name' => "DNS {$hostname->fqdn}",
                'target' => $hostname->fqdn,
                'alert_level' => AlertLevel::Normal,
            ];
        }

        return $definitions;
    }
}
