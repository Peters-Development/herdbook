<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_the_login_page_renders(): void
    {
        $this->get(route('filament.admin.auth.login'))->assertOk();
    }

    public function test_there_is_no_registration_and_no_password_reset(): void
    {
        $this->assertFalse(Route::has('filament.admin.auth.register'));
        $this->assertFalse(Route::has('filament.admin.auth.password-reset.request'));
    }

    public function test_a_user_without_two_factor_authentication_must_set_it_up_first(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
    }

    public function test_a_user_with_two_factor_authentication_reaches_the_dashboard(): void
    {
        $this->actingAs(User::factory()->withAppAuthentication()->create())
            ->get('/')
            ->assertOk();
    }

    public function test_the_two_factor_secret_is_stored_encrypted(): void
    {
        $user = User::factory()->withAppAuthentication()->create();

        $stored = $user->getConnection()->table('users')->where('id', $user->id)->value('app_authentication_secret');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $stored);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->getAppAuthenticationSecret());
    }

    public function test_authentication_and_two_factor_also_guard_livewire_update_requests(): void
    {
        $this->get(route('filament.admin.auth.login'));

        $persistent = Livewire::getPersistentMiddleware();

        $this->assertContains(Authenticate::class, $persistent);
        $this->assertContains(EnsureMultiFactorAuthenticationIsEnabled::class, $persistent);
    }
}
