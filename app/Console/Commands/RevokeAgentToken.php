<?php

namespace App\Console\Commands;

use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Takes an agent's access away: its next request gets a 401. One agent by
 * name, every agent of an account, or every agent at all.
 */
class RevokeAgentToken extends Command
{
    protected $signature = 'agents:revoke
        {name? : The name given at agents:create}
        {--user= : Revoke every token of this account (email address)}
        {--all : Revoke every agent token}';

    protected $description = 'Revoke an agent token, those of an account, or all of them';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $email = trim((string) $this->option('user'));

        if (count(array_filter([$name !== '', $email !== '', $this->option('all')])) !== 1) {
            $this->components->error('Give the name of an agent, --user=<email> or --all (one of them).');

            return self::FAILURE;
        }

        if ($name !== '') {
            $agent = AgentToken::query()->where('name', $name)->first();

            if ($agent === null) {
                $this->components->error('No agent with that name. See agents:list.');

                return self::FAILURE;
            }

            $agent->delete();
            $this->components->info("Agent \"{$agent->name}\" revoked.");

            return self::SUCCESS;
        }

        if ($email !== '') {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->components->error('No account with that email address.');

                return self::FAILURE;
            }

            $count = $user->revokeAgentTokens();
        } else {
            $count = AgentToken::revoke(AgentToken::query()->get());
        }

        // A kill switch in a runbook: nothing left to revoke is fine.
        $this->components->info(match ($count) {
            0 => 'No agent tokens to revoke.',
            1 => '1 agent revoked.',
            default => "{$count} agents revoked.",
        });

        return self::SUCCESS;
    }
}
