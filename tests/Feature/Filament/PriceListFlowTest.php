<?php

namespace Tests\Feature\Filament;

use App\Enums\CostPeriod;
use App\Enums\ProviderKind;
use App\Filament\Pages\Costs;
use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Providers\Pages\CreateProvider;
use App\Filament\Resources\Providers\Pages\EditProvider;
use App\Filament\Resources\Providers\Pages\ViewProvider;
use App\Filament\Resources\Providers\ProviderResource;
use App\Models\Domain;
use App\Models\DomainPrice;
use App\Models\Provider;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * The price list of a registrar, from its form to the domains that use it.
 */
class PriceListFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private Provider $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
        $this->registrar = Provider::factory()->registrar()->create(['name' => 'Regi']);
    }

    public function test_a_registrar_keeps_its_price_list_in_its_form(): void
    {
        Livewire::test(EditProvider::class, ['record' => $this->registrar->getRouteKey()])
            ->assertSee('Prijslijst')
            ->fillForm(['domainPrices' => [
                'a' => ['extension' => '.COM', 'cost_amount' => '9.95'],
                'b' => ['extension' => 'co.uk', 'cost_amount' => '7.50'],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['co.uk' => '7.50', 'com' => '9.95'], $this->registrar->domainPrices()->orderBy('extension')->pluck('cost_amount', 'extension')->all());
    }

    public function test_the_same_extension_twice_or_a_url_is_refused(): void
    {
        Livewire::test(EditProvider::class, ['record' => $this->registrar->getRouteKey()])
            ->fillForm(['domainPrices' => [
                'a' => ['extension' => 'com', 'cost_amount' => '9.95'],
                'b' => ['extension' => '.COM', 'cost_amount' => '8.00'],
            ]])
            ->call('save')
            ->assertHasFormErrors(['domainPrices']);

        Livewire::test(EditProvider::class, ['record' => $this->registrar->getRouteKey()])
            ->fillForm(['domainPrices' => ['a' => ['extension' => 'https://example.com', 'cost_amount' => '9.95']]])
            ->call('save')
            ->assertHasFormErrors(['domainPrices.a.extension']);

        $this->assertSame(0, $this->registrar->domainPrices()->count());
    }

    public function test_only_a_registrar_or_one_with_prices_shows_a_price_list(): void
    {
        $host = Provider::factory()->create(['kinds' => [ProviderKind::Hosting->value]]);

        Livewire::test(EditProvider::class, ['record' => $host->getRouteKey()])
            ->assertDontSee('Prijslijst');

        DomainPrice::factory()->for($host)->create(['extension' => 'com']);

        Livewire::test(EditProvider::class, ['record' => $host->getRouteKey()])
            ->assertSee('Prijslijst');
    }

    public function test_the_provider_page_lists_its_prices_with_their_domains(): void
    {
        $this->price('com', '9.95');
        $this->domain('a.com');
        $this->domain('b.com', ['cost_amount' => '12.00', 'cost_period' => CostPeriod::Year]);

        $this->get(ProviderResource::getUrl('view', ['record' => $this->registrar]))
            ->assertOk()
            ->assertSee('.com: € 9,95 · 2 domeinen · 1 met een eigen bedrag');
    }

    public function test_domains_with_just_the_list_price_as_their_own_move_to_the_list_at_once(): void
    {
        $this->price('com', '9.95');
        $same = $this->domain('same.com', ['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year]);
        $other = $this->domain('other.com', ['cost_amount' => '12.00', 'cost_period' => CostPeriod::Year]);
        $monthly = $this->domain('monthly.com', ['cost_amount' => '9.95', 'cost_period' => CostPeriod::Month]);

        Livewire::test(ViewProvider::class, ['record' => $this->registrar->getRouteKey()])
            ->assertActionVisible('usePriceList')
            ->callAction('usePriceList')
            ->assertNotified('1 domein gebruikt nu de prijslijst.')
            // Done: gone at once, not after the next click.
            ->assertActionHidden('usePriceList');

        $this->assertNull($same->fresh()->cost_amount);
        $this->assertTrue($same->fresh()->usesPriceList());
        $this->assertSame('12.00', $other->fresh()->cost_amount);
        $this->assertSame('9.95', $monthly->fresh()->cost_amount);

        Livewire::test(ViewProvider::class, ['record' => $this->registrar->getRouteKey()])
            ->assertActionHidden('usePriceList');
    }

    public function test_the_domain_form_shows_the_list_price_or_its_absence(): void
    {
        $this->price('com', '9.95');

        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'example.com', 'registrar_id' => $this->registrar->id])
            ->assertSee('9,95 uit de prijslijst')
            ->fillForm(['name' => 'example.nl'])
            ->assertSee('Geen prijs voor .nl bij Regi')
            ->assertActionVisible(TestAction::make('addToPriceList')->schemaComponent('cost_amount', schema: 'form'))
            ->fillForm(['name' => 'example.com', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year->value])
            ->assertSee('Gelijk aan de prijslijst: laat het leeg om prijswijzigingen te volgen.')
            ->assertDontSee('Toevoegen aan de prijslijst')
            // A browser lets this through a number field: no server error.
            ->fillForm(['cost_amount' => '1e3'])
            ->assertOk()
            ->assertDontSee('Gelijk aan de prijslijst');
    }

    public function test_only_a_valid_name_can_add_to_the_price_list(): void
    {
        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'example.com/path', 'registrar_id' => $this->registrar->id])
            ->assertDontSee('Toevoegen aan de prijslijst')
            ->fillForm(['name' => 'example.'.str_repeat('a', 70)])
            ->assertDontSee('Toevoegen aan de prijslijst')
            ->fillForm(['name' => 'example.org'])
            ->assertSee('Toevoegen aan de prijslijst');
    }

    public function test_two_lines_can_swap_extensions(): void
    {
        $com = $this->price('com', '9.95');
        $nl = $this->price('nl', '6.50');

        Livewire::test(EditProvider::class, ['record' => $this->registrar->getRouteKey()])
            ->fillForm([
                'support_phone' => '+31 20 000 0000',
                'domainPrices' => [
                    "record-{$com->id}" => ['extension' => 'nl', 'cost_amount' => '9.95'],
                    "record-{$nl->id}" => ['extension' => 'com', 'cost_amount' => '6.50'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('nl', $com->fresh()->extension);
        $this->assertSame('com', $nl->fresh()->extension);
        $this->assertSame('+31 20 000 0000', $this->registrar->fresh()->support_phone);
    }

    public function test_a_registrar_is_created_with_its_price_list(): void
    {
        Livewire::test(CreateProvider::class)
            ->fillForm([
                'name' => 'Newreg',
                'kinds' => [ProviderKind::Registrar->value],
                'domainPrices' => ['a' => ['extension' => 'nl', 'cost_amount' => '6.50']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('6.50', Provider::query()->where('name', 'Newreg')->sole()->domainPrices()->sole()->cost_amount);
    }

    public function test_the_price_list_is_on_the_form_of_a_provider_with_domains_but_no_registrar_kind(): void
    {
        $former = Provider::factory()->create(['kinds' => [ProviderKind::Hosting->value]]);
        Domain::factory()->create(['registrar_id' => $former->id]);

        Livewire::test(EditProvider::class, ['record' => $former->getRouteKey()])
            ->assertSee('Prijslijst');
    }

    public function test_adding_a_price_keeps_an_own_amount_that_differs_and_starts_empty_for_a_monthly_one(): void
    {
        $differs = $this->domain('differs.nl', ['cost_amount' => '15.00', 'cost_period' => CostPeriod::Year]);

        Livewire::test(EditDomain::class, ['record' => $differs->getRouteKey()])
            ->callAction(TestAction::make('addToPriceList')->schemaComponent('cost_amount', schema: 'form'), ['cost_amount' => '6.50'])
            ->assertFormSet(['cost_amount' => '15.00']);

        $monthly = $this->domain('monthly.org', ['cost_amount' => '1.00', 'cost_period' => CostPeriod::Month]);

        Livewire::test(EditDomain::class, ['record' => $monthly->getRouteKey()])
            ->mountAction(TestAction::make('addToPriceList')->schemaComponent('cost_amount', schema: 'form'))
            ->assertSchemaStateSet(['cost_amount' => null], 'mountedActionSchema0');
    }

    public function test_a_provider_without_prices_has_no_price_list_on_its_page(): void
    {
        $this->get(ProviderResource::getUrl('view', ['record' => $this->registrar]))
            ->assertOk()
            ->assertDontSee('Prijslijst');

        Livewire::test(ViewProvider::class, ['record' => $this->registrar->getRouteKey()])
            ->assertActionHidden('usePriceList');
    }

    public function test_the_first_domain_of_an_extension_fills_the_price_list(): void
    {
        $domain = $this->domain('example.nl', ['cost_amount' => '6.50', 'cost_period' => CostPeriod::Year]);

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->callAction(TestAction::make('addToPriceList')->schemaComponent('cost_amount', schema: 'form'), ['cost_amount' => '6.50'])
            ->assertNotified('.nl toegevoegd aan de prijslijst.')
            ->assertFormSet(['cost_amount' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('6.50', $this->registrar->domainPrices()->where('extension', 'nl')->value('cost_amount'));
        $this->assertTrue($domain->fresh()->usesPriceList());
    }

    public function test_the_list_and_the_page_say_where_an_amount_comes_from_and_filter_on_it(): void
    {
        $this->price('com', '9.95');
        $listed = $this->domain('listed.com');
        $own = $this->domain('own.com', ['cost_amount' => '12.00', 'cost_period' => CostPeriod::Year]);
        $none = $this->domain('none.nl');

        Livewire::test(ListDomains::class)
            ->assertSee('Uit de prijslijst van Regi')
            ->filterTable('amount', 'list')
            ->assertCanSeeTableRecords([$listed])
            ->assertCanNotSeeTableRecords([$own, $none])
            ->filterTable('amount', 'own')
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$listed, $none])
            ->removeTableFilters()
            ->filterTable('missing_cost')
            ->assertCanSeeTableRecords([$none])
            ->assertCanNotSeeTableRecords([$listed, $own]);

        Livewire::test(ViewDomain::class, ['record' => $listed->getRouteKey()])
            ->assertSee('€ 9,95 per jaar')
            ->assertSee('Uit de prijslijst van Regi');
    }

    public function test_a_bulk_action_moves_domains_to_the_price_list_and_names_what_changes(): void
    {
        $this->price('com', '9.95');
        $other = $this->domain('other.com', ['cost_amount' => '12.00', 'cost_period' => CostPeriod::Year]);
        $unlisted = $this->domain('unlisted.nl', ['cost_amount' => '6.00', 'cost_period' => CostPeriod::Year]);

        Livewire::test(ListDomains::class)
            ->selectTableRecords([$other, $unlisted])
            ->mountAction(TestAction::make('usePriceList')->table()->bulk())
            ->assertMountedActionModalSee('other.com: € 12,00 per jaar → € 9,95 per jaar')
            ->callMountedAction()
            ->assertNotified('1 domein gebruikt nu de prijslijst.');

        $this->assertTrue($other->fresh()->usesPriceList());
        $this->assertSame('6.00', $unlisted->fresh()->cost_amount, 'No price for it in the list: it keeps its own.');

        Livewire::test(ListDomains::class)
            ->selectTableRecords([$unlisted])
            ->callAction(TestAction::make('usePriceList')->table()->bulk())
            ->assertNotified('1 overgeslagen: geen prijs in de lijst, of geen eigen bedrag.');
    }

    public function test_the_costs_page_names_the_missing_price_once(): void
    {
        $this->domain('a.nl');
        $this->domain('b.nl');
        Domain::factory()->create(['name' => 'nowhere.nl']);

        $this->get(Costs::getUrl())
            ->assertOk()
            ->assertSee('Regi heeft geen prijs voor .nl (2 domeinen): zet die in de prijslijst, of geef de domeinen een eigen bedrag.')
            ->assertSee(ProviderResource::getUrl('edit', ['record' => $this->registrar]))
            // All of them, as the list behind the link.
            ->assertSee('3 domeinen zonder bedrag of periode');
    }

    private function price(string $extension, string $amount): DomainPrice
    {
        return DomainPrice::factory()->for($this->registrar)->create(['extension' => $extension, 'cost_amount' => $amount]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function domain(string $name, array $attributes = []): Domain
    {
        return Domain::factory()->create(['name' => $name, 'registrar_id' => $this->registrar->id, ...$attributes]);
    }
}
