<?php

namespace App\Console\Commands;

use App\Enums\AgentAccess;
use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\text;

/**
 * A token for an agent (Claude Code and the like) to use the MCP server for
 * an account (docs/agents.md). The token is shown once; the database only
 * keeps its hash. It acts without the second factor, so only an account that
 * has one gets it. An expired agent of the same account is replaced under its
 * own name, so what it recorded stays recognisable.
 */
class CreateAgentToken extends Command
{
    protected $signature = 'agents:create
        {email? : Email address of the account the agent acts for}
        {name? : What the agent is called, e.g. "deploy-agent"}
        {--write : Let it record and change maintenance, not only read}
        {--days=90 : Days until the token expires (1 to 365)}';

    protected $description = 'Create a token for an agent to use the MCP server (the token is shown once)';

    public function handle(): int
    {
        $email = trim((string) ($this->argument('email') ?? text('Email address of the account', required: true)));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error('No account with that email address.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactor()) {
            $this->components->error('This account has no two-factor authentication yet. Sign in once and set it up first: an agent token works without it, so only accounts that have one get a token.');

            return self::FAILURE;
        }

        $attributes = [
            'name' => trim((string) ($this->argument('name') ?? text('Name of the agent', placeholder: 'deploy-agent', required: true))),
            'days' => $this->option('days'),
        ];

        $expired = $user->agentTokens()->where('name', $attributes['name'])->where('expires_at', '<=', now())->first();

        $validator = Validator::make($attributes, [
            // Shown as "via <name>" next to what it records, and printed in a revoke command.
            'name' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique(AgentToken::class)->ignore($expired)],
            'days' => ['required', 'integer', 'between:1,365'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $access = $this->option('write') ? AgentAccess::Write : AgentAccess::Read;
        [$agent, $token] = DB::transaction(function () use ($expired, $user, $attributes, $access): array {
            $expired?->delete();

            return AgentToken::issue($user, $attributes['name'], $access, now()->addDays((int) $attributes['days']));
        });

        $expires = $agent->expires_at->timezone(config('herdbook.display_timezone'))->format('Y-m-d H:i');
        $can = $access === AgentAccess::Write ? 'read and write' : 'read only';

        $this->components->info(($expired !== null ? "Expired agent \"{$agent->name}\" replaced" : "Agent \"{$agent->name}\" created")." for {$user->email} ({$can}), valid until {$expires}. Its token (shown only now):");
        $this->line('  '.$token);
        $this->newLine();
        $this->line('  MCP server: '.rtrim((string) config('app.url'), '/').'/mcp');
        $this->line('  Keep the token in your keychain, never in a file in a repository: docs/agents.md.');
        $this->newLine();

        if (! config('herdbook.mcp.enabled')) {
            $this->components->warn('The MCP server is off: set HERDBOOK_MCP_ENABLED=true and recreate the containers.');
        }

        $this->components->warn("Anyone with this token acts as {$user->email} without a second factor. Revoke it with: agents:revoke \"{$agent->name}\"");

        return self::SUCCESS;
    }
}
