<?php

namespace App\Console\Commands;

use App\Models\AgentToken;
use Illuminate\Console\Command;

/**
 * Which agents may use the MCP server, for which account, and when each was
 * last used: a token nobody uses any more is one to revoke.
 */
class ListAgentTokens extends Command
{
    protected $signature = 'agents:list';

    protected $description = 'List the agent tokens, their accounts and when they were last used';

    public function handle(): int
    {
        $agents = AgentToken::query()->with('user')->orderBy('name')->get();

        if ($agents->isEmpty()) {
            $this->components->info('No agent tokens yet. Create one with agents:create.');

            return self::SUCCESS;
        }

        $timezone = config('herdbook.display_timezone');

        $this->table(['Name', 'Account', 'Access', 'Created', 'Expires', 'Last used'], $agents->map(fn (AgentToken $agent): array => [
            $agent->name,
            $agent->user->email,
            $agent->access->value,
            $agent->created_at?->timezone($timezone)->format('Y-m-d H:i'),
            $agent->expires_at->timezone($timezone)->format('Y-m-d H:i').($agent->isExpired() ? ' (expired)' : ''),
            $agent->last_used_at?->timezone($timezone)->format('Y-m-d H:i') ?? 'never',
        ]));

        return self::SUCCESS;
    }
}
