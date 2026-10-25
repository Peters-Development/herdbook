<?php

namespace Tests\Fakes;

use App\Services\Checks\NetworkProbe;
use App\Services\Checks\ProbeFailed;
use Carbon\CarbonImmutable;

/**
 * The network for tests: everything answers, unless told otherwise.
 */
class FakeProbe implements NetworkProbe
{
    public bool $online = true;

    /** @var array<string, string> "host:port" => error */
    public array $closedPorts = [];

    /** @var array<string, CarbonImmutable> host => certificate expiry */
    public array $certificates = [];

    /** @var array<string, list<string>> name => addresses */
    public array $addresses = [];

    /** @var list<string> */
    public array $unreachable = [];

    /** @var list<string> every "host:port" connected to */
    public array $connections = [];

    /** @var list<string> every URL asked by reachable() */
    public array $reachabilityChecks = [];

    public function tcp(string $host, int $port, float $timeoutSeconds): int
    {
        $this->connections[] = "{$host}:{$port}";

        if (! $this->online || isset($this->closedPorts["{$host}:{$port}"])) {
            throw new ProbeFailed($this->closedPorts["{$host}:{$port}"] ?? "Poort {$port} op {$host} niet bereikbaar");
        }

        return 12;
    }

    public function certificateExpiry(string $host, int $port, float $timeoutSeconds): CarbonImmutable
    {
        return $this->certificates[$host] ?? throw new ProbeFailed("Geen TLS-verbinding met {$host}:{$port}");
    }

    public function resolve(string $name): array
    {
        return $this->addresses[$name] ?? throw new ProbeFailed("{$name} heeft geen A- of AAAA-record.");
    }

    public function reachable(string $url, float $timeoutSeconds): bool
    {
        $this->reachabilityChecks[] = $url;

        return $this->online;
    }

    public function ping(string $host, float $timeoutSeconds): int
    {
        if (in_array($host, $this->unreachable, true)) {
            throw new ProbeFailed("{$host} antwoordt niet op ping.");
        }

        return 3;
    }
}
