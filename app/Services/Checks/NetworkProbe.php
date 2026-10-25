<?php

namespace App\Services\Checks;

use Carbon\CarbonImmutable;

/**
 * The network calls the checks make, besides the HTTP checks themselves
 * (those go through the Http facade). An interface, so tests replace the
 * network. Every call is bounded in time.
 */
interface NetworkProbe
{
    /**
     * Opens a TCP connection; returns the time it took in milliseconds.
     *
     * @throws ProbeFailed
     */
    public function tcp(string $host, int $port, float $timeoutSeconds): int;

    /**
     * The expiry of the certificate a TLS server presents.
     *
     * @throws ProbeFailed
     */
    public function certificateExpiry(string $host, int $port, float $timeoutSeconds): CarbonImmutable;

    /**
     * The A and AAAA addresses of a name, asked at a public resolver.
     *
     * @return list<string>
     *
     * @throws ProbeFailed
     */
    public function resolve(string $name): array;

    /**
     * One ICMP echo; returns the round trip in milliseconds.
     *
     * @throws ProbeFailed
     */
    public function ping(string $host, float $timeoutSeconds): int;

    /**
     * Whether a URL answers at all (any HTTP status): name resolution, TCP
     * and TLS of the host's own line work. Used by ConnectivityGuard.
     */
    public function reachable(string $url, float $timeoutSeconds): bool;
}
