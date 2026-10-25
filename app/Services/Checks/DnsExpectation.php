<?php

namespace App\Services\Checks;

use App\Enums\ServerRole;
use App\Models\Check;
use App\Models\EnvironmentServer;

/**
 * Where a hostname should point: its own expected IPs, or the public
 * addresses of the app and proxy servers of its environment. Derived from
 * the inventory, so a migration that forgot a DNS record shows up.
 */
final class DnsExpectation
{
    /**
     * @return list<string> empty: no expectation (behind a proxy, or nothing known)
     */
    public function for(Check $check): array
    {
        $hostname = $check->hostname;

        if ($hostname === null || $hostname->behind_proxy) {
            return [];
        }

        if (filled($hostname->expected_ips)) {
            return array_values($hostname->expected_ips);
        }

        if ($hostname->environment_id === null) {
            return [];
        }

        return EnvironmentServer::query()
            ->with('server')
            ->where('environment_id', $hostname->environment_id)
            ->whereIn('role', [ServerRole::App, ServerRole::Proxy])
            ->get()
            ->flatMap(fn (EnvironmentServer $assignment): array => array_filter([
                $assignment->server->public_ipv4,
                $assignment->server->public_ipv6,
            ]))
            ->unique()
            ->values()
            ->all();
    }
}
