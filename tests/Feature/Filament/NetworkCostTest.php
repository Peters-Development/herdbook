<?php

namespace Tests\Feature\Filament;

use App\Enums\CostPeriod;
use App\Enums\ProviderKind;
use App\Filament\Pages\Costs;
use App\Filament\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Networks\Pages\ListNetworks;
use App\Filament\Resources\Networks\Pages\ViewNetwork;
use App\Filament\Widgets\NetworkRenewals;
use App\Filament\Widgets\RenewalCalendar;
use App\Models\Network;
use App\Models\Provider;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * What the internet line of a network costs, and its contract.
 */
class NetworkCostTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_a_network_is_created_with_what_its_line_costs_and_its_contract(): void
    {
        $this->signIn();
        $isp = Provider::factory()->create(['name' => 'Vodafone', 'kinds' => [ProviderKind::Internet->value]]);

        Livewire::test(CreateNetwork::class)
            ->fillForm([
                'name' => 'Main office',
                'provider_id' => $isp->id,
                'cost_amount' => '49.50',
                'cost_period' => CostPeriod::Month->value,
                'expires_on' => '2027-03-01',
                'renewal_period_months' => 12,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = Network::query()->sole();
        $this->assertSame('594.00', (string) $network->yearlyCost());
        $this->assertSame('2027-03-01', $network->expires_on->toDateString());
    }

    public function test_the_contract_shows_once_there_is_an_internet_provider(): void
    {
        $this->signIn();
        $isp = Provider::factory()->create(['kinds' => [ProviderKind::Internet->value]]);

        Livewire::test(CreateNetwork::class)
            ->assertSchemaComponentHidden('expires_on')
            ->fillForm(['provider_id' => $isp->id])
            ->assertSchemaComponentVisible('expires_on');
    }

    public function test_emptying_the_provider_and_the_date_is_saved(): void
    {
        $this->signIn();
        $network = Network::factory()->create(['provider_id' => Provider::factory()->create(['kinds' => [ProviderKind::Internet->value]])->id, 'expires_on' => '2027-03-01']);

        Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
            ->fillForm(['provider_id' => null, 'expires_on' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $network->refresh();
        $this->assertNull($network->provider_id);
        $this->assertNull($network->expires_on);
        $this->assertFalse($network->hasContract());
    }

    public function test_its_contract_is_renewed_from_its_page_and_in_bulk_from_the_list(): void
    {
        $this->signIn();
        $network = Network::factory()->create(['provider_id' => Provider::factory()->create()->id, 'expires_on' => '2027-03-01']);

        Livewire::test(ViewNetwork::class, ['record' => $network->getRouteKey()])
            ->callAction('renew');
        $this->assertSame('2028-03-01', $network->fresh()->expires_on->toDateString());

        // In the list as a bulk action, as for servers.
        Livewire::test(ListNetworks::class)
            ->selectTableRecords([$network])
            ->callAction(TestAction::make('renew')->table()->bulk());
        $this->assertSame('2029-03-01', $network->fresh()->expires_on->toDateString());
    }

    public function test_the_list_filters_on_what_needs_attention_or_has_no_amount(): void
    {
        $this->signIn();
        Date::setTestNow('2027-01-20');
        $provider = Provider::factory()->create();
        $due = Network::factory()->create(['provider_id' => $provider->id, 'expires_on' => '2027-02-10', 'cost_amount' => '49.50', 'cost_period' => CostPeriod::Month]);
        $unpriced = Network::factory()->create(['provider_id' => $provider->id, 'cost_amount' => null]);
        $lan = Network::factory()->create(['provider_id' => null, 'cost_amount' => null]);

        Livewire::test(ListNetworks::class)
            ->filterTable('needs_attention')
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$unpriced, $lan]);
        Livewire::test(ListNetworks::class)
            ->filterTable('missing_cost')
            ->assertCanSeeTableRecords([$unpriced])
            ->assertCanNotSeeTableRecords([$due, $lan]);
    }

    public function test_a_contract_that_needs_attention_is_on_the_dashboard_and_the_cost_page(): void
    {
        $this->signIn();
        Date::setTestNow('2027-01-20');
        $this->assertFalse(NetworkRenewals::canView());

        $network = Network::factory()->create(['name' => 'Main office', 'provider_id' => Provider::factory()->create(['name' => 'Vodafone'])->id, 'expires_on' => '2027-02-10', 'auto_renews' => false, 'cost_amount' => '49.50', 'cost_period' => CostPeriod::Month]);
        $this->app->forgetScopedInstances(); // a new request

        $this->assertTrue(NetworkRenewals::canView());
        Livewire::test(NetworkRenewals::class)->assertCanSeeTableRecords([$network]);
        // Not a technical value: not in mono.
        Livewire::test(RenewalCalendar::class)->assertSeeHtml('<span class="hb-cal-label">Main office</span>');
        $this->get(Costs::getUrl())->assertOk()->assertSeeInOrder(['Per leverancier', 'Vodafone', '€ 594,00']);
    }
}
