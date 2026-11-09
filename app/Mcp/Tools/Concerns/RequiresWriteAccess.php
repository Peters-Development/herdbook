<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\User;
use Laravel\Mcp\Request;

/**
 * A tool that changes something: only for an agent whose token may write.
 * The package leaves it out of the list and refuses a call otherwise.
 */
trait RequiresWriteAccess
{
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->currentAgentToken()?->canWrite() === true;
    }
}
