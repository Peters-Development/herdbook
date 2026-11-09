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
     * A call of one tool, over HTTP like an agent makes it.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function callTool(string $token, string $tool, array $arguments = []): TestResponse
    {
        return $this->mcp($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    }

    /**
     * A call that must succeed; its structured content.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function callToolSuccessfully(string $token, string $tool, array $arguments = []): array
    {
        $response = $this->callTool($token, $tool, $arguments)->assertOk();
        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return $response->json('result.structuredContent');
    }

    /**
     * A call that the tool must refuse; what it says.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function callToolError(string $token, string $tool, array $arguments = []): string
    {
        $response = $this->callTool($token, $tool, $arguments)->assertOk();
        $this->assertTrue($response->json('result.isError'), 'The call succeeded: '.json_encode($arguments));

        return $response->json('result.content.0.text');
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

        // A request of its own: whoever the test was acting as before acts on after it.
        $before = $this->app['auth']->guard()->user();

        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], [
            'Accept' => 'application/json, text/event-stream',
            ...($method !== 'initialize' ? ['MCP-Protocol-Version' => '2025-06-18'] : []),
            ...($token !== null ? ['Authorization' => "Bearer {$token}"] : []),
            ...$headers,
        ]);

        $this->app['auth']->forgetGuards();
        // As after a real request: what is built once per request (HiddenValues, ProjectHealth) is built again.
        $this->app->forgetScopedInstances();

        if ($before !== null) {
            $this->actingAs($before);
        }

        return $response;
    }
}
