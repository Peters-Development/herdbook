<?php

namespace Tests\Feature\Filament;

use App\Enums\CostPeriod;
use App\Enums\ProviderKind;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Providers\Pages\EditProvider;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Widgets\RenewalCalendar;
use App\Filament\Widgets\StatusOverview;
use App\Filament\Widgets\SubscriptionRenewals;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Subscription;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class SubscriptionFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_a_subscription_is_created_for_a_project(): void
    {
        $this->signIn();
        $provider = Provider::factory()->create(['name' => 'JetBrains']);
        $project = Project::factory()->create();

        Livewire::test(CreateSubscription::class)
            ->fillForm([
                'name' => 'All Products Pack',
                'provider_id' => $provider->id,
                'project_id' => $project->id,
                'cost_amount' => '289',
                'cost_period' => CostPeriod::Year->value,
                'expires_on' => '2027-03-01',
                'renewal_period_months' => 12,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $subscription = Subscription::query()->sole();
        $this->assertSame('289.00', $subscription->cost_amount);
        $this->assertSame(CostPeriod::Year, $subscription->cost_period);
        $this->assertTrue($subscription->project->is($project));
        $this->assertTrue($subscription->provider->is($provider));
    }

    public function test_an_amount_needs_a_period_and_no_amount_stores_no_period(): void
    {
        $this->signIn();

        Livewire::test(CreateSubscription::class)
            ->fillForm(['name' => 'Postmark', 'cost_amount' => '15', 'cost_period' => null, 'renewal_period_months' => 1])
            ->call('create')
            ->assertHasFormErrors(['cost_period']);

        Livewire::test(CreateSubscription::class)
            ->fillForm(['name' => 'Gratis', 'cost_amount' => null, 'renewal_period_months' => 1])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Subscription::query()->where('name', 'Gratis')->sole()->cost_period);
    }

    public function test_a_subscription_renews_like_a_domain(): void
    {
        $this->signIn();
        $subscription = Subscription::factory()->create(['expires_on' => '2027-03-12', 'renewal_period_months' => 12]);

        Livewire::test(ListSubscriptions::class)
            ->callAction(TestAction::make('renew')->table($subscription));

        $this->assertSame('2028-03-12', $subscription->fresh()->expires_on->toDateString());

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
            ->callAction('renew');

        $this->assertSame('2029-03-12', $subscription->fresh()->expires_on->toDateString());
    }

    public function test_a_subscription_that_needs_attention_shows_up_on_the_dashboard(): void
    {
        $this->signIn();
        Date::setTestNow('2027-01-20');
        Subscription::factory()->create(['name' => 'Later', 'expires_on' => '2028-01-01']);

        $this->assertFalse(SubscriptionRenewals::canView());

        $due = Subscription::factory()->create(['name' => 'Plausible', 'expires_on' => '2027-02-10', 'auto_renews' => false]);
        $this->app->forgetScopedInstances(); // a new request

        $this->assertTrue(SubscriptionRenewals::canView());
        Livewire::test(SubscriptionRenewals::class)->assertCanSeeTableRecords([$due]);
        // A subscription's name is no technical value: not in mono, unlike a domain.
        Livewire::test(RenewalCalendar::class)->assertSee('Plausible')->assertDontSee('Later')->assertSeeHtml('<span class="hb-cal-label">Plausible</span>');
        Livewire::test(StatusOverview::class)
            ->assertSee('Eerst: Plausible, 10-02-2027')
            ->assertSee(e(SubscriptionResource::getUrl('index', ['filters' => ['needs_attention' => ['isActive' => true]]])), false);
    }

    public function test_a_provider_or_client_with_subscriptions_cannot_be_deleted(): void
    {
        $this->signIn();
        $provider = Provider::factory()->create();
        $client = Client::factory()->create();
        Subscription::factory()->create(['provider_id' => $provider->id, 'client_id' => $client->id]);

        Livewire::test(EditProvider::class, ['record' => $provider->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('Deze leverancier is nog in gebruik');
        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('Deze klant heeft nog projecten, domeinen of abonnementen');

        $this->assertModelExists($provider);
        $this->assertModelExists($client);
    }

    public function test_the_lists_filter_on_what_has_no_amount(): void
    {
        $this->signIn();
        $priced = Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year]);
        $unpriced = Domain::factory()->create(['cost_amount' => null]);
        $rented = Server::factory()->create(['cost_amount' => null, 'provider_id' => Provider::factory()->create()->id]);
        $own = Server::factory()->create(['cost_amount' => null]);

        Livewire::test(ListDomains::class)
            ->filterTable('missing_cost')
            ->assertCanSeeTableRecords([$unpriced])
            ->assertCanNotSeeTableRecords([$priced]);
        Livewire::test(ListServers::class)
            ->filterTable('missing_cost')
            ->assertCanSeeTableRecords([$rented])
            ->assertCanNotSeeTableRecords([$own]);
    }

    public function test_subscriptions_filter_on_what_has_no_amount(): void
    {
        $this->signIn();
        $priced = Subscription::factory()->create();
        $unpriced = Subscription::factory()->create(['cost_amount' => null, 'cost_period' => null]);
        $stopped = Subscription::factory()->create(['cost_amount' => null, 'cost_period' => null, 'renewal_stopped_at' => now()]);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('missing_cost')
            ->assertCanSeeTableRecords([$unpriced])
            ->assertCanNotSeeTableRecords([$priced, $stopped]);
    }

    public function test_the_lists_sort_on_what_something_costs_per_year(): void
    {
        $this->signIn();
        $monthly = Domain::factory()->create(['cost_amount' => '2.00', 'cost_period' => CostPeriod::Month]); // 24 per year
        $yearly = Domain::factory()->create(['cost_amount' => '20.00', 'cost_period' => CostPeriod::Year]);
        $cheap = Domain::factory()->create(['cost_amount' => '5.00', 'cost_period' => CostPeriod::Year]);

        Livewire::test(ListDomains::class)
            ->sortTable('cost_amount')
            ->assertCanSeeTableRecords([$cheap, $yearly, $monthly], inOrder: true)
            ->sortTable('cost_amount', 'desc')
            ->assertCanSeeTableRecords([$monthly, $yearly, $cheap], inOrder: true);

        $servers = [
            Server::factory()->create(['cost_amount' => '1.00', 'cost_period' => CostPeriod::Month]),
            Server::factory()->create(['cost_amount' => '13.00', 'cost_period' => CostPeriod::Year]),
        ];

        Livewire::test(ListServers::class)
            ->sortTable('cost_amount')
            ->assertCanSeeTableRecords($servers, inOrder: true);
    }

    public function test_setting_an_amount_in_bulk_needs_a_valid_amount_and_a_period(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['cost_amount' => null]);

        Livewire::test(ListDomains::class)
            ->selectTableRecords([$domain])
            ->callAction(TestAction::make('setCost')->table()->bulk(), ['cost_amount' => '-1', 'cost_period' => null])
            ->assertHasFormErrors(['cost_amount', 'cost_period']);

        $this->assertNull($domain->fresh()->cost_amount);
    }

    public function test_one_amount_is_set_for_several_domains_at_once(): void
    {
        $this->signIn();
        $domains = Domain::factory()->count(2)->create(['cost_amount' => null]);
        $other = Domain::factory()->create(['cost_amount' => null]);

        Livewire::test(ListDomains::class)
            ->selectTableRecords($domains)
            ->callAction(TestAction::make('setCost')->table()->bulk(), ['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year->value])
            ->assertHasNoFormErrors()
            ->assertNotified('Bedrag ingesteld voor 2 domeinen: € 9,95 per jaar');

        foreach ($domains as $domain) {
            $this->assertSame('9.95', $domain->fresh()->cost_amount);
            $this->assertSame(CostPeriod::Year, $domain->fresh()->cost_period);
            // Through Eloquent: each one is in its activity log.
            $this->assertTrue(Activity::query()->where('subject_type', 'domain')->where('subject_id', $domain->id)->where('event', 'updated')->exists());
        }
        $this->assertNull($other->fresh()->cost_amount);
    }

    public function test_a_new_provider_is_created_from_the_subscription_form(): void
    {
        $this->signIn();

        Livewire::test(CreateSubscription::class)
            ->callFormComponentAction('provider_id', 'createOption', ['name' => 'Plausible', 'kinds' => [ProviderKind::Other->value]])
            ->assertHasNoFormComponentActionErrors();

        $this->assertTrue(Provider::query()->where('name', 'Plausible')->exists());
    }

    public function test_a_monthly_subscription_starts_with_a_monthly_term_and_the_term_follows_the_period(): void
    {
        $this->signIn();

        Livewire::test(CreateSubscription::class)
            ->assertSchemaStateSet(['renewal_period_months' => 1, 'cost_period' => CostPeriod::Month])
            ->fillForm(['cost_period' => CostPeriod::Year->value])
            ->assertSchemaStateSet(['renewal_period_months' => 12])
            ->fillForm(['renewal_period_months' => 3, 'cost_period' => CostPeriod::Month->value])
            ->assertSchemaStateSet(['renewal_period_months' => 3]);
    }

    public function test_a_monthly_subscription_without_a_date_runs_on_without_a_renewal_status(): void
    {
        $this->signIn();
        $runsOn = Subscription::factory()->create(['cost_period' => CostPeriod::Month, 'expires_on' => null]);
        $yearly = Subscription::factory()->create(['cost_period' => CostPeriod::Year, 'expires_on' => null]);

        $this->assertFalse($runsOn->hasContract());
        $this->assertTrue($yearly->hasContract());
        $this->assertSame([$yearly->id], Subscription::query()->withContract()->pluck('id')->all());

        Livewire::test(ViewSubscription::class, ['record' => $runsOn->getRouteKey()])
            ->assertDontSee('Geen datum');
    }

    public function test_the_cost_of_a_domain_is_saved_from_its_form_and_logged(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['cost_amount' => null]);

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->fillForm(['cost_amount' => '12.99', 'cost_period' => CostPeriod::Year->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('12.99', $domain->fresh()->cost_amount);
        $this->assertTrue(Activity::query()->where('subject_type', 'domain')->where('subject_id', $domain->id)->where('event', 'updated')->exists());
    }

    public function test_the_cost_of_a_server_is_edited_on_its_form(): void
    {
        $this->signIn();
        $server = Server::factory()->create();

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['cost_amount' => '7.59', 'cost_period' => CostPeriod::Month->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('91.08', (string) $server->fresh()->yearlyCost());
    }
}
