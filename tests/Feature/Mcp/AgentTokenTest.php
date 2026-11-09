<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgentAccess;
use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AgentTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_create_shows_the_token_once_and_keeps_only_a_hash(): void
    {
        config(['app.url' => 'https://herdbook.example.ts.net', 'herdbook.mcp.enabled' => true]);
        $user = User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);

        Artisan::call('agents:create', ['email' => 'ada@example.com', 'name' => 'deploy-agent']);
        $output = Artisan::output();
        preg_match('/\bhb_[A-Za-z0-9]{48}\b/', $output, $match);

        $this->assertNotEmpty($match, 'The token is shown.');
        $this->assertStringContainsString('https://herdbook.example.ts.net/mcp', $output);
        $this->assertStringNotContainsString('HERDBOOK_MCP_ENABLED', $output);
        $agent = AgentToken::query()->sole();
        $this->assertTrue($agent->user->is($user));
        $this->assertSame(hash('sha256', $match[0]), $agent->token_hash);
        $this->assertTrue(AgentToken::findByToken($match[0])->is($agent));
        $this->assertArrayNotHasKey('token_hash', $agent->toArray());
    }

    public function test_a_token_reads_by_default_and_expires_after_ninety_days(): void
    {
        Date::setTestNow('2026-11-09 12:00:00');
        User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);

        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'reader'])->assertSuccessful();
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'writer', '--write' => true, '--days' => 7])->assertSuccessful();

        $reader = AgentToken::query()->where('name', 'reader')->sole();
        $writer = AgentToken::query()->where('name', 'writer')->sole();
        $this->assertSame(AgentAccess::Read, $reader->access);
        $this->assertFalse($reader->canWrite());
        $this->assertSame('2027-02-07 12:00:00', $reader->expires_at->toDateTimeString());
        $this->assertTrue($writer->canWrite());
        $this->assertSame('2026-11-16 12:00:00', $writer->expires_at->toDateTimeString());
    }

    public function test_agents_create_says_when_the_mcp_server_is_off(): void
    {
        config(['herdbook.mcp.enabled' => false]);
        User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);

        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'deploy-agent'])
            ->expectsOutputToContain('HERDBOOK_MCP_ENABLED=true')
            ->assertSuccessful();
    }

    public function test_only_an_account_with_two_factor_gets_a_token(): void
    {
        User::factory()->create(['email' => 'new@example.com']);

        $this->artisan('agents:create', ['email' => 'new@example.com', 'name' => 'deploy-agent'])
            ->expectsOutputToContain('no two-factor authentication')
            ->assertFailed();

        $this->assertSame(0, AgentToken::query()->count());
    }

    public function test_agents_create_refuses_an_unknown_account_a_taken_name_and_odd_lifetimes(): void
    {
        $user = User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);
        AgentToken::issue($user, 'deploy-agent', AgentAccess::Read, now()->addDay());

        $this->artisan('agents:create', ['email' => 'nobody@example.com', 'name' => 'other'])->assertFailed();
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'deploy-agent'])->assertFailed();
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'other', '--days' => 0])->assertFailed();
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'other', '--days' => 366])->assertFailed();
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => str_repeat('a', 256)])->assertFailed();
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'two words'])->assertFailed();
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => "a\nb"])->assertFailed();
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'x$(id)"'])->assertFailed();

        $this->assertSame(1, AgentToken::query()->count());
    }

    public function test_an_expired_agent_is_replaced_under_its_own_name(): void
    {
        $user = User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);
        $other = User::factory()->withAppAuthentication()->create(['email' => 'bob@example.com']);
        [$expired] = AgentToken::issue($user, 'deploy-agent', AgentAccess::Read, now()->subDay());
        AgentToken::issue($other, 'bobs-agent', AgentAccess::Read, now()->subDay());

        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'deploy-agent'])
            ->expectsOutputToContain('Expired agent "deploy-agent" replaced')
            ->assertSuccessful();
        // Not the expired agent of another account.
        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'bobs-agent'])->assertFailed();

        $agent = AgentToken::query()->where('name', 'deploy-agent')->sole();
        $this->assertFalse($agent->is($expired));
        $this->assertFalse($agent->isExpired());
        $this->assertTrue(Activity::query()->where('subject_type', 'agent_token')->where('subject_id', $expired->id)->where('event', 'deleted')->exists());
    }

    public function test_creating_and_revoking_an_agent_is_logged_with_its_account_without_its_token(): void
    {
        $user = User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);

        $this->artisan('agents:create', ['email' => 'ada@example.com', 'name' => 'deploy-agent'])->assertSuccessful();
        $this->artisan('agents:revoke', ['name' => 'deploy-agent'])->assertSuccessful();

        $logged = Activity::query()->where('subject_type', 'agent_token')->get();

        $this->assertSame(['created', 'deleted'], $logged->pluck('event')->all());
        $this->assertSame([$user->id, $user->id], $logged->map(fn (Activity $activity) => $activity->properties['user_id'])->all());
        $this->assertSame('deploy-agent', $logged->last()->properties['label']);
        $this->assertStringNotContainsString('token_hash', $logged->toJson());
        $this->assertSame(0, AgentToken::query()->count());
    }

    public function test_agents_list_shows_each_agent_its_account_and_whether_it_expired(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        $user = User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);
        AgentToken::issue($user, 'reader', AgentAccess::Read, now()->addDays(30));
        [$writer] = AgentToken::issue($user, 'writer', AgentAccess::Write, now()->addDay());
        $writer->markUsed();
        Date::setTestNow('2026-10-25 12:00:00');

        $this->artisan('agents:list')
            ->expectsTable(['Name', 'Account', 'Access', 'Created', 'Expires', 'Last used'], [
                ['reader', 'ada@example.com', 'read', '2026-10-23 14:00', '2026-11-22 13:00', 'never'],
                ['writer', 'ada@example.com', 'write', '2026-10-23 14:00', '2026-10-24 14:00 (expired)', '2026-10-23 14:00'],
            ])
            ->assertSuccessful();
    }

    public function test_agents_revoke_takes_one_agent_those_of_an_account_or_all(): void
    {
        $ada = User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);
        $bob = User::factory()->withAppAuthentication()->create(['email' => 'bob@example.com']);
        foreach (['ada-1' => $ada, 'ada-2' => $ada, 'bob-1' => $bob, 'bob-2' => $bob] as $name => $user) {
            AgentToken::issue($user, $name, AgentAccess::Read, now()->addDay());
        }

        $this->artisan('agents:revoke', ['name' => 'ada-1'])->assertSuccessful();
        $this->assertSame(['ada-2', 'bob-1', 'bob-2'], AgentToken::query()->orderBy('name')->pluck('name')->all());

        $this->artisan('agents:revoke', ['--user' => 'bob@example.com'])->assertSuccessful();
        $this->assertSame(['ada-2'], AgentToken::query()->pluck('name')->all());

        $this->artisan('agents:revoke', ['--all' => true])->assertSuccessful();
        $this->assertSame(0, AgentToken::query()->count());
        $this->assertSame(4, Activity::query()->where('subject_type', 'agent_token')->where('event', 'deleted')->count(), 'Each revoke is logged.');

        // Nothing left: still fine, it is a kill switch.
        $this->artisan('agents:revoke', ['--all' => true])->expectsOutputToContain('No agent tokens to revoke.')->assertSuccessful();
    }

    public function test_agents_revoke_needs_exactly_one_thing_that_exists(): void
    {
        $user = User::factory()->withAppAuthentication()->create(['email' => 'ada@example.com']);
        AgentToken::issue($user, 'deploy-agent', AgentAccess::Read, now()->addDay());

        $this->artisan('agents:revoke')->assertFailed();
        $this->artisan('agents:revoke', ['name' => 'deploy-agent', '--all' => true])->assertFailed();
        $this->artisan('agents:revoke', ['name' => 'attic'])->assertFailed();
        $this->artisan('agents:revoke', ['--user' => 'nobody@example.com'])->assertFailed();

        $this->assertSame(1, AgentToken::query()->count());
    }

    public function test_an_expired_token_is_pruned_a_week_later(): void
    {
        Date::setTestNow('2026-11-09 12:00:00');
        $user = User::factory()->withAppAuthentication()->create();
        AgentToken::issue($user, 'expired-long-ago', AgentAccess::Read, now()->subDays(8));
        AgentToken::issue($user, 'expired-yesterday', AgentAccess::Read, now()->subDay());
        AgentToken::issue($user, 'valid', AgentAccess::Read, now()->addDay());

        $this->artisan('model:prune', ['--model' => [AgentToken::class]])->assertSuccessful();

        $this->assertSame(['expired-yesterday', 'valid'], AgentToken::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_deleting_an_account_revokes_its_agents(): void
    {
        $user = User::factory()->withAppAuthentication()->create();
        AgentToken::issue($user, 'deploy-agent', AgentAccess::Read, now()->addDay());

        $user->delete();

        $this->assertSame(0, AgentToken::query()->count());
    }

    public function test_the_agent_of_a_request_is_never_stored_with_the_account(): void
    {
        $user = User::factory()->withAppAuthentication()->create();
        [$agent] = AgentToken::issue($user, 'deploy-agent', AgentAccess::Read, now()->addDay());

        $user->withAgentToken($agent)->update(['name' => 'Ada']);

        $this->assertTrue($user->currentAgentToken()->is($agent));
        $this->assertArrayNotHasKey('currentAgentToken', $user->toArray());
        $this->assertNull($user->fresh()->currentAgentToken());
    }
}
