<?php

namespace Tests\Concerns;

use App\Enums\AgentAccess;
use App\Models\AgentToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait ActsAsAgent
{
    /**
     * An agent of an account with two-factor (a new one unless given).
     *
     * @return array{0: AgentToken, 1: string} the agent and its token
     */
    protected function agentToken(?User $user = null, AgentAccess $access = AgentAccess::Read, ?CarbonImmutable $expiresAt = null, ?string $name = null): array
    {
        return AgentToken::issue(
            $user ?? User::factory()->withAppAuthentication()->create(),
            $name ?? 'agent-'.Str::lower(Str::random(8)),
            $access,
            $expiresAt ?? now()->addDays(90),
        );
    }

    /**
     * A JSON-RPC request to /mcp as Claude Code sends it: the 2025-06-18
     * handshake, then that protocol version in a header.
     *
     * @param  array<string, mixed>|null  $params
     * @param  array<string, string>  $headers
     */
    protected function mcp(?string $token, string $method = 'initialize', ?array $params = null, array $headers = []): TestResponse
    {
        $params ??= $method === 'initialize' ? [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'claude-code', 'version' => '2.0.0'],
        ] : [];

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], [
            'Accept' => 'application/json, text/event-stream',
            ...($method !== 'initialize' ? ['MCP-Protocol-Version' => '2025-06-18'] : []),
            ...($token !== null ? ['Authorization' => "Bearer {$token}"] : []),
            ...$headers,
        ]);
    }
}
