<?php

namespace Tests\Feature\Costs;

use App\Enums\CostPeriod;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Providers\Pages\ViewProvider;
use App\Models\Domain;
use App\Models\DomainPrice;
use App\Models\Provider;
use App\Services\Costs\CostOverview;
use App\Services\Costs\Money;
use App\Services\Costs\RecurringCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * A domain without an amount of its own costs what its registrar's price
 * list says for its extension.
 */
class DomainPriceTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private Provider $registrar;

    /** Another registrar with another price for .com: lazy loading of prices throws with two. */
    private Provider $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar = Provider::factory()->registrar()->create(['name' => 'Regi']);
        DomainPrice::factory()->for($this->registrar)->create(['extension' => 'com', 'cost_amount' => '9.95']);
        DomainPrice::factory()->for($this->registrar)->create(['extension' => 'co.uk', 'cost_amount' => '7.50']);
        $this->other = Provider::factory()->registrar()->create(['name' => 'Other']);
        DomainPrice::factory()->for($this->other)->create(['extension' => 'com', 'cost_amount' => '20.00']);
    }

    public function test_a_domain_without_an_amount_costs_the_price_of_its_extension(): void
    {
        $listed = $this->domain('example.com');
        $british = $this->domain('example.co.uk');

        $this->assertSame('9.95', $listed->costAmount());
        $this->assertSame(CostPeriod::Year, $listed->costPeriod());
        $this->assertTrue($listed->usesPriceList());
        $this->assertSame('7.50', $british->costAmount(), 'co.uk, not uk.');
        $this->assertSame('20.00', $this->domain('there.com', ['registrar_id' => $this->other->id])->costAmount());

        // Two registrars: their prices must be eager-loaded (Model::shouldBeStrict()).
        $this->assertSame('37.45', (string) $this->overview()->yearly);
    }

    public function test_a_registrar_changed_after_loading_counts_with_its_own_prices(): void
    {
        $domain = $this->domain('example.com')->load('registrar.domainPrices');

        $domain->registrar_id = $this->other->id;

        $this->assertSame('20.00', $domain->costAmount());
    }

    public function test_an_amount_of_its_own_wins(): void
    {
        $own = $this->domain('own.com', ['cost_amount' => '15.00', 'cost_period' => CostPeriod::Year]);

        $this->assertSame('15.00', $own->costAmount());
        $this->assertFalse($own->usesPriceList());
        $this->assertSame('€ 15,00 per jaar', Money::describe($own));
    }

    public function test_at_the_list_price_is_the_same_in_php_and_sql(): void
    {
        $same = $this->domain('same.com', ['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year]);
        $this->domain('more.com', ['cost_amount' => '12.00', 'cost_period' => CostPeriod::Year]);
        $this->domain('monthly.com', ['cost_amount' => '9.95', 'cost_period' => CostPeriod::Month]);
        $this->domain('listed.com');
        $this->domain('there.com', ['registrar_id' => $this->other->id, 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year]);

        $this->assertSame([$same->id], Domain::query()->atListPrice()->pluck('id')->all());
        $this->assertSame([$same->id], Domain::query()->with('registrar.domainPrices')->get()->filter->isAtListPrice()->modelKeys());
    }

    public function test_an_amount_without_a_period_stays_its_own_and_missing(): void
    {
        $half = $this->domain('half.com', ['cost_amount' => '15.00']);

        $this->assertFalse($half->hasCost());
        $this->assertTrue(Domain::query()->missingCost()->whereKey($half->id)->exists());
    }

    public function test_a_renewal_of_two_years_costs_twice_the_price(): void
    {
        $domain = $this->domain('long.com', ['renewal_period_months' => 24]);

        $this->assertSame('19.90', (string) $domain->renewalCost());
    }

    public function test_another_extension_another_registrar_or_none_is_missing_as_the_overview_says(): void
    {
        $this->domain('example.com');
        $this->domain('example.nl');
        Domain::factory()->create(['name' => 'elsewhere.com', 'registrar_id' => Provider::factory()->registrar()->create()->id]);
        Domain::factory()->create(['name' => 'nobody.com']);
        $this->domain('stopped.nl', ['renewal_stopped_at' => now()]);
        $this->domain('half.com', ['cost_amount' => '15.00']);
        // A period without an amount: the price list.
        $this->domain('period.com', ['cost_period' => CostPeriod::Month]);

        $missing = Domain::query()->missingCost()->pluck('name')->sort()->values()->all();

        $this->assertSame(['elsewhere.com', 'example.nl', 'half.com', 'nobody.com'], $missing);
        $this->assertSame(count($missing), $this->overview()->missing()[Domain::class], 'The filter finds what the overview counts.');
    }

    public function test_a_local_domain_never_takes_a_price(): void
    {
        $local = $this->domain('home.com');
        // Written past the model: the hook that clears the registrar does not run.
        DB::table('domains')->where('id', $local->id)->update(['local_only' => true]);

        $this->assertNull($local->fresh()->costAmount());
        $this->assertFalse(Domain::query()->missingCost()->whereKey($local->id)->exists());
    }

    public function test_a_new_price_counts_at_once(): void
    {
        $this->domain('example.com');
        $this->domain('other.com');

        $this->registrar->domainPrices()->where('extension', 'com')->sole()->update(['cost_amount' => '11.00']);

        $this->assertSame('22.00', (string) $this->overview()->yearly);
    }

    public function test_the_extension_is_the_same_in_php_and_sql(): void
    {
        foreach (['example.com', 'example.co.uk', 'a.b.example.org', 'localhost'] as $name) {
            $domain = Domain::factory()->create(['name' => $name]);

            $this->assertSame(Domain::extensionOf($name), DB::table('domains')->where('id', $domain->id)->value(DB::raw(Domain::extensionSql())));
        }
    }

    public function test_an_extension_is_written_one_way(): void
    {
        $price = DomainPrice::factory()->for($this->registrar)->create(['extension' => ' .ORG. ']);

        $this->assertSame('org', $price->extension);
    }

    public function test_the_list_sorts_on_the_price_of_each_registrar_and_loads_them_in_one_go(): void
    {
        $this->signIn();
        $cheap = $this->domain('cheap.co.uk');
        $listed = $this->domain('listed.com');
        $own = $this->domain('own.com', ['cost_amount' => '12.00', 'cost_period' => CostPeriod::Year]);
        $there = $this->domain('there.com', ['registrar_id' => $this->other->id]);
        $monthly = $this->domain('monthly.com', ['cost_amount' => '2.00', 'cost_period' => CostPeriod::Month]);

        Livewire::test(ListDomains::class)
            ->assertTableColumnStateSet('cost_amount', '€ 9,95 per jaar', $listed)
            ->assertTableColumnStateSet('cost_amount', '€ 20,00 per jaar', $there)
            ->sortTable('cost_amount')
            ->assertCanSeeTableRecords([$cheap, $listed, $own, $there, $monthly], inOrder: true);
    }

    public function test_a_price_change_is_logged_on_the_provider_page(): void
    {
        $this->signIn();
        $this->registrar->domainPrices()->where('extension', 'com')->sole()->update(['cost_amount' => '11.00']);

        Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $this->registrar, 'pageClass' => ViewProvider::class])
            ->assertSee('Prijs .com')
            ->assertSee('€ 9,95 → € 11,00');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function domain(string $name, array $attributes = []): Domain
    {
        return Domain::factory()->create(['name' => $name, 'registrar_id' => $this->registrar->id, ...$attributes]);
    }

    private function overview(): CostOverview
    {
        $this->app->forgetScopedInstances();

        return app(RecurringCosts::class)->overview();
    }
}
