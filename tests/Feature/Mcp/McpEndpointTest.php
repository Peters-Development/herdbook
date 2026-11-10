<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class McpEndpointTest extends TestCase
{
    use ActsAsAgent, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['herdbook.mcp.enabled' => true]);
    }

    public function test_while_it_is_off_the_server_answers_not_found(): void
    {
        config(['herdbook.mcp.enabled' => false]);
        [, $token] = $this->agentToken();

        $this->mcp($token)->assertNotFound();
        $this->options('/mcp')->assertNotFound();
    }

    public function test_without_a_good_token_the_server_says_no(): void
    {
        [, $token] = $this->agentToken();

        $this->mcp(null)
            ->assertUnauthorized()
            ->assertJson(['message' => 'Dit agent-token is onbekend of ingetrokken.'])
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->mcp('hb_wrong')->assertUnauthorized();
        $this->mcp($token)->assertOk();

        $this->artisan('agents:revoke', ['--all' => true])->assertSuccessful();

        $this->mcp($token)->assertUnauthorized();
    }

    public function test_the_token_is_checked_before_the_package_reads_the_request(): void
    {
        [, $token] = $this->agentToken();
        // Without the Mcp-Method header the package would answer 400.
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]];

        $this->postJson('/mcp', $request)->assertUnauthorized();
        $this->postJson('/mcp', $request, ['Authorization' => "Bearer {$token}"])->assertBadRequest();
    }

    public function test_wrong_tokens_are_limited_without_locking_out_a_good_one(): void
    {
        Log::spy();
        [, $token] = $this->agentToken();

        foreach (range(1, 30) as $attempt) {
            $this->mcp("hb_wrong-{$attempt}")->assertUnauthorized();
        }

        $this->mcp('hb_one-more')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->mcp($token)->assertOk();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_expired_token_is_refused(): void
    {
        [, $token] = $this->agentToken(expiresAt: now()->subMinute());

        $this->mcp($token)
            ->assertUnauthorized()
            ->assertJson(['message' => 'Dit agent-token is verlopen. Maak een nieuw aan met agents:create.']);
    }

    public function test_a_token_only_works_while_its_account_has_two_factor(): void
    {
        $user = User::factory()->withAppAuthentication()->create();
        [, $token] = $this->agentToken($user);
        $this->mcp($token)->assertOk();

        $user->forceFill(['app_authentication_secret' => null])->save();

        $this->mcp($token)
            ->assertForbidden()
            ->assertJson(['message' => 'Het account van deze agent heeft geen tweestapsverificatie. Het token werkt weer zodra die is ingesteld.']);
    }

    public function test_one_agent_asking_too_often_is_slowed_down(): void
    {
        $user = User::factory()->withAppAuthentication()->create();
        [$agent, $token] = $this->agentToken($user);

        $this->mcp($token)->assertOk();
        $this->assertSame(1, RateLimiter::attempts("agent-token:{$agent->id}"), 'Every request counts.');

        foreach (range(2, 120) as $request) {
            RateLimiter::hit("agent-token:{$agent->id}", 60);
        }

        $this->mcp($token)->assertTooManyRequests()->assertHeader('Retry-After');
        // Another agent of the same account has a limit of its own.
        $this->mcp($this->agentToken($user)[1])->assertOk();
    }

    public function test_an_agent_that_keeps_trying_an_expired_token_is_slowed_down_too(): void
    {
        [$agent, $token] = $this->agentToken(expiresAt: now()->subMinute());

        $this->mcp($token)->assertUnauthorized();

        $this->assertSame(1, RateLimiter::attempts("agent-token:{$agent->id}"));
    }

    public function test_requests_from_a_browser_are_refused(): void
    {
        [, $token] = $this->agentToken();

        $this->mcp($token, headers: ['Origin' => 'https://elsewhere.example'])
            ->assertForbidden()
            ->assertJson(['message' => 'Verzoeken vanuit een browser worden geweigerd.']);
    }

    public function test_the_handshake_names_the_server_and_the_timezone_of_times_without_an_offset(): void
    {
        config(['herdbook.display_timezone' => 'Europe/Amsterdam']);
        [, $token] = $this->agentToken();

        $response = $this->mcp($token)->assertOk();

        $this->assertSame('Herdbook', $response->json('result.serverInfo.name'));
        $this->assertSame('2025-06-18', $response->json('result.protocolVersion'));
        $this->assertStringContainsString('Times without an offset are in Europe/Amsterdam', $response->json('result.instructions'));
    }

    public function test_an_agent_can_list_the_tools(): void
    {
        [, $token] = $this->agentToken();

        $this->mcp($token, 'tools/list')
            ->assertOk()
            ->assertJsonStructure(['result' => ['tools']]);
    }

    public function test_answers_are_never_cached_set_no_cookie_and_carry_the_security_headers(): void
    {
        [, $token] = $this->agentToken();

        $response = $this->mcp($token)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_only_post_is_allowed(): void
    {
        [, $token] = $this->agentToken();

        foreach (['GET', 'DELETE', 'OPTIONS'] as $method) {
            $this->call($method, '/mcp', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"])
                ->assertMethodNotAllowed()
                ->assertHeader('Allow', 'POST');
        }

        // A browser's preflight never gets that far.
        $this->options('/mcp', [], ['Origin' => 'https://elsewhere.example'])->assertForbidden();
    }

    public function test_the_agent_acts_as_its_account_in_its_language(): void
    {
        $user = User::factory()->withAppAuthentication()->create(['locale' => 'en']);
        [$agent, $token] = $this->agentToken($user);
        Event::listen(Authenticated::class, function (Authenticated $event) use (&$authenticated): void {
            $authenticated = $event->user;
        });

        $this->mcp($token)->assertOk();

        $this->assertTrue($authenticated->is($user));
        $this->assertTrue($authenticated->currentAgentToken()->is($agent));
        $this->assertSame('There is no maintenance with id 999. list_maintenance finds them.', $this->callToolError($token, 'get_maintenance', ['id' => 999]));
    }

    public function test_after_a_request_the_test_acts_as_before(): void
    {
        $person = User::factory()->withAppAuthentication()->create();
        [, $token] = $this->agentToken();
        $this->actingAs($person);

        $this->mcp($token)->assertOk();

        // A request of its own: the agent's account doesn't stay signed in.
        $this->assertTrue(auth()->user()->is($person));
        $this->assertNull(auth()->user()->currentAgentToken());
    }

    public function test_an_agent_is_seen_at_most_once_a_minute(): void
    {
        Date::setTestNow('2026-11-09 12:00:00');
        [$agent, $token] = $this->agentToken();

        $this->mcp($token);
        $this->assertSame('2026-11-09 12:00:00', $agent->fresh()->last_used_at->toDateTimeString());

        Date::setTestNow('2026-11-09 12:00:30');
        $this->mcp($token);
        $this->assertSame('2026-11-09 12:00:00', $agent->fresh()->last_used_at->toDateTimeString());

        Date::setTestNow('2026-11-09 12:01:30');
        $this->mcp($token);
        $this->assertSame('2026-11-09 12:01:30', $agent->fresh()->last_used_at->toDateTimeString());
    }
}
