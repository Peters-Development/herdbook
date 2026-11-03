<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Models\Domain;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Livewire\Topbar;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class HintsTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const string EXPLANATION = 'Waar je deze klant vindt in je facturatie- of CRM-pakket.';

    public function test_an_explanation_shows_under_its_field_by_default(): void
    {
        $this->signIn();

        Livewire::test(CreateClient::class)
            ->assertSee(self::EXPLANATION)
            ->assertFormFieldExists('external_url', fn (TextInput $field): bool => $field->getHintIcon() === null);
    }

    public function test_turned_off_an_explanation_moves_behind_an_icon_next_to_the_label(): void
    {
        $this->signIn()->update(['shows_hints' => false]);

        Livewire::test(CreateClient::class)
            ->assertDontSeeHtml('external_url-helper-text')
            ->assertFormFieldExists('external_url', fn (TextInput $field): bool => $field->getHintIcon() === Heroicon::OutlinedInformationCircle
                && $field->getHintIconTooltip() === self::EXPLANATION);
    }

    public function test_a_section_explanation_shows_under_its_heading_or_behind_an_icon(): void
    {
        $user = $this->signIn();
        $domain = Domain::factory()->create();
        $explanation = 'De verloopdatum vul je zelf in. "Verlengen" schuift hem één termijn op.';

        $renewal = $this->renewalSection($domain);
        $this->assertSame($explanation, $renewal->getDescription());
        $this->assertSame([], $renewal->getChildSchema(Section::AFTER_HEADER_SCHEMA_KEY)?->getComponents() ?? []);

        $user->update(['shows_hints' => false]);

        $renewal = $this->renewalSection($domain);
        $icon = collect($renewal->getChildSchema(Section::AFTER_HEADER_SCHEMA_KEY)->getComponents())->sole();
        $this->assertNull($renewal->getDescription());
        $this->assertInstanceOf(Icon::class, $icon);
        $this->assertSame($explanation, $icon->getTooltip());
    }

    public function test_a_toggle_keeps_its_explanation_behind_an_icon_too(): void
    {
        $this->signIn()->update(['shows_hints' => false]);

        Livewire::test(EditDomain::class, ['record' => Domain::factory()->create()->getRouteKey()])
            ->assertFormFieldExists('local_only', fn (Toggle $field): bool => $field->getHintIcon() === Heroicon::OutlinedInformationCircle);
    }

    public function test_the_user_menu_turns_explanations_off_and_on_again(): void
    {
        $user = $this->signIn();
        // the topbar belongs to the panel of the page it is on
        $this->get(CreateClient::getUrl());

        Livewire::test(Topbar::class)->callAction('toggleHints');

        $this->assertFalse($user->fresh()->shows_hints);

        Livewire::test(Topbar::class)->callAction('toggleHints');

        $this->assertTrue($user->fresh()->shows_hints);
    }

    public function test_the_profile_has_the_setting_and_says_what_the_app_is_missing_also_without_explanations(): void
    {
        config(['herdbook.alerts.pushover_token' => null]);
        $this->signIn()->update(['shows_hints' => false]);

        Livewire::test(EditProfile::class)
            ->assertFormFieldExists('shows_hints', fn (Toggle $field): bool => $field->getHintIcon() === Heroicon::OutlinedInformationCircle)
            ->assertSee('Niet ingericht: PUSHOVER_APP_TOKEN in .env')
            ->fillForm(['shows_hints' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Filament::auth()->user()->fresh()->shows_hints);
    }

    private function renewalSection(Domain $domain): Section
    {
        return Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->instance()
            ->form
            ->getComponent(fn ($component): bool => $component instanceof Section && $component->getHeading() === 'Verlenging');
    }
}
