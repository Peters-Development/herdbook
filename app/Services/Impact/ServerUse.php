<?php

namespace App\Services\Impact;

use App\Enums\ServerRole;

/**
 * An environment uses a server directly: it runs on it in $role, or the
 * mail or DNS ($role) of $domainId (the domain of one of its hostnames)
 * runs there.
 */
final readonly class ServerUse
{
    public function __construct(
        public int $environmentId,
        public int $serverId,
        public ServerRole $role,
        public ?int $domainId = null,
    ) {}

    /**
     * "runs" (on the server, in $role), or "dns" or "mail" through a domain.
     */
    public function kind(): string
    {
        return match (true) {
            $this->domainId === null => 'runs',
            $this->role === ServerRole::Dns => 'dns',
            default => 'mail',
        };
    }

    /**
     * Which use names the link when an environment uses a server in several
     * ways: running on it, then DNS through a domain (it takes the website
     * down too), then mail through a domain. Lower comes first.
     */
    public function rank(): int
    {
        return match ($this->kind()) {
            'runs' => 0,
            'dns' => 1,
            default => 2,
        };
    }
}
