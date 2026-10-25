<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * The interface speaks the language of the signed-in account, or the default
 * one (APP_LOCALE). The suite runs with Dutch as the default.
 */
class LanguageTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_the_panel_speaks_the_language_of_the_account(): void
    {
        $this->signIn()->update(['locale' => 'en']);

        $this->get('/')->assertSuccessful()->assertSee('Inventory')->assertDontSee('Beheer');
    }

    public function test_an_account_without_a_language_gets_the_default(): void
    {
        $this->signIn();

        $this->get('/')->assertSuccessful()->assertSee('Beheer')->assertDontSee('Inventory');
    }

    public function test_the_default_can_be_english(): void
    {
        config(['herdbook.default_locale' => 'en']);
        app()->setLocale('en');
        $this->signIn();

        $this->get('/')->assertSuccessful()->assertSee('Inventory');
    }

    public function test_a_language_herdbook_does_not_speak_falls_back_to_the_default(): void
    {
        $user = $this->signIn();
        $user->forceFill(['locale' => 'xx'])->save();

        $this->assertNull($user->preferredLocale());
        $this->get('/')->assertSuccessful()->assertSee('Beheer');
    }

    public function test_the_profile_switches_the_language_and_reloads_in_it(): void
    {
        $user = $this->signIn();

        Livewire::test(EditProfile::class)
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(EditProfile::getUrl());

        $this->assertSame('en', $user->fresh()->locale);
        $this->get(EditProfile::getUrl())->assertSee('Language');
    }

    public function test_saving_the_profile_in_the_same_language_stays_on_the_page(): void
    {
        $this->signIn();

        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Someone else'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();
    }

    public function test_the_profile_only_accepts_languages_herdbook_speaks(): void
    {
        $this->signIn();

        Livewire::test(EditProfile::class)
            ->fillForm(['locale' => 'xx'])
            ->call('save')
            ->assertHasFormErrors(['locale']);
    }

    public function test_an_account_prefers_its_own_language_or_none(): void
    {
        $this->assertSame('en', User::factory()->create(['locale' => 'en'])->preferredLocale());
        $this->assertNull(User::factory()->create()->preferredLocale());
    }

    public function test_dates_follow_the_language(): void
    {
        $date = CarbonImmutable::parse('2026-03-01');

        app()->setLocale('en');
        $this->assertSame('1 Mar 2026', $date->format(__('formats.date')));
        $this->assertSame('March', $date->isoFormat('MMMM'));

        app()->setLocale('nl');
        $this->assertSame('01-03-2026', $date->format(__('formats.date')));
        $this->assertSame('maart', $date->isoFormat('MMMM'));
    }
}
