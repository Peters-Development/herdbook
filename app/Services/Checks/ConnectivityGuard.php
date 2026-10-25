<?php

namespace App\Services\Checks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Is it them, or is it us?" Before a failure counts, one of a few
 * well-known URLs must answer: that proves name resolution, TCP and TLS of
 * the host's own line. When none does, nothing is recorded and nothing is
 * sent, instead of every check going red at once.
 *
 * Only "offline" is cached (briefly): a line that just dropped is noticed
 * at the next failure, not half a minute later.
 */
final class ConnectivityGuard
{
    private const string OFFLINE_KEY = 'checks:offline';

    public function __construct(private readonly NetworkProbe $probe) {}

    public function isOnline(): bool
    {
        if (Cache::has(self::OFFLINE_KEY)) {
            return false;
        }

        foreach ((array) config('herdbook.checks.reference_urls') as $url) {
            if ($this->probe->reachable($url, 4.0)) {
                return true;
            }
        }

        Cache::put(self::OFFLINE_KEY, true, 30);
        Log::warning('Own connection seems down: no reference URL answers. Check results are not recorded.');

        return false;
    }
}
