<?php

namespace Tests\Feature\Console;

use App\Enums\AgentAccess;
use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ResetTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_the_secret_and_the_recovery_codes(): void
    {
        $user = User::factory()->withAppAuthentication()->create();
        $user->saveAppAuthenticationRecoveryCodes(['code-one', 'code-two']);

        $this->artisan('users:reset-two-factor', ['email' => $user->email])->assertSuccessful();

        $user = $user->fresh();
        $this->assertNull($user->getAppAuthenticationSecret());
        $this->assertNull($user->getAppAuthenticationRecoveryCodes());
    }

    public function test_the_account_must_set_up_two_factor_again_afterwards(): void
    {
        $user = User::factory()->withAppAuthentication()->create();

        $this->artisan('users:reset-two-factor', ['email' => $user->email])->assertSuccessful();

        $this->actingAs($user->fresh())
            ->get('/')
            ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
    }

    public function test_it_fails_for_an_unknown_e_mail_address(): void
    {
        $user = User::factory()->withAppAuthentication()->create();

        $this->artisan('users:reset-two-factor', ['email' => 'someone-else@example.com'])->assertFailed();

        $this->assertNotNull($user->fresh()->getAppAuthenticationSecret());
    }

    public function test_it_signs_the_account_out_everywhere(): void
    {
        $user = User::factory()->withAppAuthentication()->create(['remember_token' => 'old-remember-token']);
        $other = User::factory()->withAppAuthentication()->create();
        foreach ([$user, $other] as $index => $owner) {
            DB::table('sessions')->insert([
                'id' => "session-{$index}",
                'user_id' => $owner->id,
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $this->artisan('users:reset-two-factor', ['email' => $user->email])->assertSuccessful();

        $this->assertNotSame('old-remember-token', $user->fresh()->getRememberToken());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_it_revokes_the_agent_tokens_of_the_account(): void
    {
        $user = User::factory()->withAppAuthentication()->create();
        $other = User::factory()->withAppAuthentication()->create();
        AgentToken::issue($user, 'deploy-agent', AgentAccess::Write, now()->addDay());
        AgentToken::issue($other, 'other-agent', AgentAccess::Write, now()->addDay());

        $this->artisan('users:reset-two-factor', ['email' => $user->email])->assertSuccessful();

        $this->assertSame(['other-agent'], AgentToken::query()->pluck('name')->all());
        $this->assertSame(1, Activity::query()->where('subject_type', 'agent_token')->where('event', 'deleted')->count(), 'Each revoke is logged.');
    }
}
