<?php

namespace App\Services\Impact;

use App\Enums\ServerRole;
use App\Models\Environment;
use App\Models\Server;
use App\Models\ServerDependency;

/**
 * An environment that goes down with a server: it runs on $server in $role,
 * and $server is the failing one or reaches it through $chain.
 */
final readonly class ImpactedEnvironment
{
    /**
     * @param  list<ServerDependency>  $chain  hops from $server towards the failing server; empty when $server is the failing one
     */
    public function __construct(
        public Environment $environment,
        public Server $server,
        public ServerRole $role,
        public array $chain,
    ) {}

    public function isDirect(): bool
    {
        return $this->chain === [];
    }

    /**
     * "app-1 → NFS → storage-1"
     */
    public function path(): string
    {
        $path = $this->server->name;

        foreach ($this->chain as $hop) {
            $path .= " → {$hop->kind} → {$hop->dependsOn->name}";
        }

        return $path;
    }
}
