<?php

namespace App\Services\Impact;

use App\Enums\ServerRole;

/**
 * An environment uses a server directly: it runs on it in $role, or the
 * mail of $domainId (the domain of one of its hostnames) runs there.
 */
final readonly class ServerUse
{
    public function __construct(
        public int $environmentId,
        public int $serverId,
        public ServerRole $role,
        public ?int $domainId = null,
    ) {}
}
