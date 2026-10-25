<?php

namespace Tests\Feature\Costs;

use App\Enums\CostPeriod;
use App\Enums\CostRange;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Client;
use App\Models\Contracts\Renewable;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Subscription;
use App\Services\Costs\CostGroup;
use App\Services\Costs\CostMonth;
use App\Services\Costs\CostOverview;
use App\Services\Costs\Payment;
use App\Services\Costs\RecurringCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class RecurringCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-24 12:00:00');
    }

    public function test_a_monthly_amount_is_paid_every_month_and_twelve_times_a_year(): void
    {
        Subscription::factory()->create(['cost_amount' => '6.80', 'cost_period' => CostPeriod::Month]);

        $overview = $this->overview();

        $this->assertSame('81.60', (string) $overview->yearly);
        $this->assertCount(12, $overview->months);
        $this->assertSame('2026-10', $overview->months[0]->key());
        $this->assertSame('2027-09', $overview->months[11]->key());

        foreach ($overview->months as $month) {
            $this->assertSame('6.80', (string) $month->total());
            $this->assertSame('6.80', (string) $month->monthly());
        }
    }

    public function test_a_yearly_amount_is_paid_on_the_expiry_date(): void
    {
        $domain = Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-12-03']);

        $overview = $this->overview();

        $this->assertSame('9.95', (string) $overview->month('2026-12')->total());
        $this->assertSame('9.95', (string) $overview->month('2026-12')->renewals());
        $this->assertSame('0', (string) $overview->month('2026-11')->total());
        $this->assertSame('2026-12-03', $overview->month('2026-12')->payments[0]->date->toDateString());
        $this->assertTrue($overview->month('2026-12')->payments[0]->item->is($domain));
        $this->assertTrue($overview->month('2026-12')->payments[0]->isPending());
    }

    public function test_a_longer_term_pays_more_years_at_once_and_less_often(): void
    {
        Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-15', 'renewal_period_months' => 24]);
        Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2027-11-15', 'renewal_period_months' => 24]);

        $overview = $this->overview();

        $this->assertSame('19.90', (string) $overview->month('2026-11')->total());
        $this->assertSame('19.90', (string) CostOverview::total($overview->months), 'The second one renews after the twelve months.');
        $this->assertSame('19.90', (string) $overview->yearly, 'Per year, both count for one year.');
    }

    public function test_a_yearly_amount_with_a_short_term_is_paid_every_term(): void
    {
        Subscription::factory()->create(['cost_amount' => '100.00', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-01', 'renewal_period_months' => 3]);

        $overview = $this->overview();

        $this->assertSame(['2026-11', '2027-02', '2027-05', '2027-08'], collect($overview->months)
            ->filter(fn (CostMonth $month): bool => $month->payments !== [])
            ->map(fn (CostMonth $month): string => $month->key())
            ->values()
            ->all());
        $this->assertSame('25.00', (string) $overview->month('2026-11')->total());
    }

    public function test_renewals_keep_the_day_of_the_month_without_drifting(): void
    {
        Subscription::factory()->create(['cost_amount' => '12.00', 'cost_period' => CostPeriod::Year, 'expires_on' => '2027-01-31', 'renewal_period_months' => 1]);

        $overview = $this->overview();

        $this->assertSame('2027-02-28', $overview->month('2027-02')->payments[0]->date->toDateString());
        $this->assertSame('2027-03-31', $overview->month('2027-03')->payments[0]->date->toDateString());
    }

    public function test_a_stopped_item_costs_nothing_per_year_and_a_monthly_one_runs_until_its_expiry_date(): void
    {
        Subscription::factory()->create(['cost_amount' => '4.00', 'cost_period' => CostPeriod::Month, 'expires_on' => '2026-12-15', 'renewal_stopped_at' => now()]);
        Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-12-15', 'renewal_stopped_at' => now()]);

        $overview = $this->overview();

        $this->assertSame('0', (string) $overview->yearly);
        $this->assertSame(0, $overview->counted);
        $this->assertSame('4.00', (string) $overview->month('2026-10')->total());
        $this->assertSame('4.00', (string) $overview->month('2026-12')->total());
        $this->assertSame('0', (string) $overview->month('2027-01')->total());
    }

    public function test_a_yearly_amount_without_an_expiry_date_counts_per_year_but_in_no_month(): void
    {
        $subscription = Subscription::factory()->create(['cost_amount' => '289.00', 'cost_period' => CostPeriod::Year, 'expires_on' => null]);

        $overview = $this->overview();

        $this->assertSame('289.00', (string) $overview->yearly);
        $this->assertSame('0', (string) CostOverview::total($overview->months));
        $this->assertCount(1, $overview->undated);
        $this->assertTrue($overview->undated[0]->is($subscription));
    }

    public function test_a_renewal_before_this_month_that_is_still_open_is_expired_not_in_a_month(): void
    {
        $september = Domain::factory()->create(['cost_amount' => '12.99', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-09-10']);
        $october = Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-10-03']);
        Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-10-30']);

        $overview = $this->overview();

        $this->assertSame([$september->id, $october->id], array_map(fn (Domain $domain): int => $domain->id, $overview->expired), 'Expired this month counts too.');
        $this->assertSame('19.90', (string) $overview->month('2026-10')->total(), 'Expired earlier this month: still in this month.');
        $this->assertSame('12.99', (string) $overview->month('2027-09')->total(), 'The renewal after it comes in its own month.');
    }

    public function test_long_expired_only_the_next_renewals_count(): void
    {
        Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2023-03-01']);

        $overview = $this->overview();

        $this->assertCount(1, $overview->expired);
        $this->assertSame('9.95', (string) CostOverview::total($overview->months));
        $this->assertSame('9.95', (string) $overview->month('2027-03')->total());
    }

    public function test_what_does_not_renew_by_itself_counts_and_is_to_be_decided(): void
    {
        Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-03', 'auto_renews' => false]);
        Subscription::factory()->create(['cost_amount' => '15.00', 'cost_period' => CostPeriod::Month]);

        $november = $this->overview()->month('2026-11');

        $this->assertSame('24.95', (string) $november->total());
        $this->assertSame('9.95', (string) $november->decide());
        $this->assertSame('0', (string) $november->renewals());
        $this->assertSame('15.00', (string) $november->monthly());
    }

    public function test_amounts_add_up_exactly(): void
    {
        Subscription::factory()->count(3)->create(['cost_amount' => '0.10', 'cost_period' => CostPeriod::Month]);
        Subscription::factory()->create(['cost_amount' => '10.00', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-01', 'renewal_period_months' => 1]);

        $overview = $this->overview();

        $this->assertSame('13.60', (string) $overview->yearly);
        $this->assertSame('0.30', (string) $overview->months[0]->total());
        // A twelfth of 10.00, rounded to cents per payment.
        $this->assertSame('1.13', (string) $overview->month('2026-11')->total());
    }

    public function test_an_item_without_an_amount_or_period_counts_nowhere_and_is_missing(): void
    {
        Domain::factory()->create(['cost_amount' => null]);
        Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => null]);
        Domain::factory()->create(['cost_amount' => null, 'renewal_stopped_at' => now()]);
        Server::factory()->create(['cost_amount' => null]); // of our own: no contract
        Server::factory()->create(['cost_amount' => null, 'provider_id' => Provider::factory()->create()->id]);
        Subscription::factory()->create(['cost_amount' => null, 'cost_period' => null]);

        $overview = $this->overview();

        $this->assertSame('0', (string) $overview->yearly);
        $this->assertSame([Domain::class => 2, Server::class => 1, Subscription::class => 1, Network::class => 0], $overview->missing());
    }

    public function test_what_has_no_amount_is_counted_as_the_filter_behind_the_link_finds_it(): void
    {
        $provider = Provider::factory()->create();

        // Every combination that matters: amount, period, expiry, stopped, provider.
        foreach ([null, '9.95'] as $amount) {
            foreach ([null, CostPeriod::Month, CostPeriod::Year] as $period) {
                foreach ([null, '2027-03-01'] as $expires) {
                    foreach ([null, now()] as $stopped) {
                        $attributes = ['cost_amount' => $amount, 'cost_period' => $period, 'expires_on' => $expires, 'renewal_stopped_at' => $stopped];
                        Domain::factory()->create($attributes);
                        Subscription::factory()->create($attributes);
                        Server::factory()->create($attributes);
                        Server::factory()->create([...$attributes, 'provider_id' => $provider->id]);
                        Network::factory()->create($attributes);
                        Network::factory()->create([...$attributes, 'provider_id' => $provider->id]);
                    }
                }
            }
        }

        $this->assertSame(
            collect(Renewable::MODELS)->mapWithKeys(fn (string $model): array => [$model => $model::query()->missingCost()->count()])->all(),
            $this->overview()->missing(),
        );
    }

    public function test_a_server_out_of_use_that_is_still_paid_for_is_pointed_out(): void
    {
        $unused = Server::factory()->create(['status' => ServerStatus::Decommissioned, 'cost_amount' => '14.95', 'cost_period' => CostPeriod::Month]);
        Server::factory()->create(['status' => ServerStatus::Decommissioned, 'cost_amount' => '14.95', 'cost_period' => CostPeriod::Month, 'expires_on' => '2026-11-01', 'renewal_stopped_at' => now()]);

        $overview = $this->overview();

        $this->assertCount(1, $overview->unused);
        $this->assertTrue($overview->unused[0]->is($unused));
    }

    public function test_this_month_and_this_quarter(): void
    {
        Date::setTestNow('2026-11-10 12:00:00');
        $overview = $this->overview();

        $this->assertSame(['2026-11'], array_map(fn (CostMonth $month): string => $month->key(), $overview->range(CostRange::Month)));
        $this->assertSame(['2026-11', '2026-12'], array_map(fn (CostMonth $month): string => $month->key(), $overview->range(CostRange::Quarter)));
        $this->assertCount(12, $overview->range(CostRange::TwelveMonths));
    }

    public function test_today_is_taken_in_the_display_timezone(): void
    {
        // 23:30 UTC on 31 October is already 1 November in Amsterdam.
        Date::setTestNow('2026-10-31 23:30:00');

        $this->assertSame('2026-11', $this->overview()->months[0]->key());
    }

    public function test_per_provider(): void
    {
        $hetzner = Provider::factory()->create(['name' => 'Hetzner']);
        $namecheap = Provider::factory()->registrar()->create(['name' => 'Namecheap']);
        Server::factory()->count(2)->create(['provider_id' => $hetzner->id, 'cost_amount' => '5.00', 'cost_period' => CostPeriod::Month]);
        Domain::factory()->create(['registrar_id' => $namecheap->id, 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year]);
        Subscription::factory()->create(['provider_id' => null, 'cost_amount' => '1.00', 'cost_period' => CostPeriod::Year]);

        $groups = $this->totals($this->overview()->byProvider);

        $this->assertSame(['Hetzner' => '120.00', 'Namecheap' => '9.95', 'Geen leverancier' => '1.00'], $groups);
    }

    public function test_per_project_a_server_for_more_projects_is_shared_and_a_domain_follows_its_hostnames(): void
    {
        $planner = Project::factory()->create(['name' => 'Pasture Planner']);
        $studbook = Project::factory()->create(['name' => 'Studbook']);
        $dedicated = $this->serverFor([$planner], '10.00');
        $shared = $this->serverFor([$planner, $studbook], '20.00');
        $this->serverFor([], '1.00');
        $domain = Domain::factory()->create(['cost_amount' => '12.00', 'cost_period' => CostPeriod::Year]);
        Hostname::factory()->count(2)->create(['domain_id' => $domain->id, 'environment_id' => $studbook->environments()->first()->id]);
        Subscription::factory()->create(['project_id' => $planner->id, 'cost_amount' => '3.00', 'cost_period' => CostPeriod::Year]);

        $overview = $this->overview();

        // A tie (12.00): the one that also shares something first.
        $this->assertSame(['Gedeeld' => '240.00', 'Pasture Planner' => '123.00', 'Studbook' => '12.00', 'Geen project' => '12.00'], $this->totals($overview->byProject));
        $bucket = collect($overview->byProject)->firstWhere('label', 'Gedeeld');
        $this->assertNull($bucket->model);
        $this->assertTrue($bucket->items[0]->item->is($shared));
        $this->assertSame('Pasture Planner, Studbook', $bucket->items[0]->detail);

        // Counted once, under "Shared", but shown with each project too.
        $group = collect($overview->byProject)->firstWhere('label', 'Pasture Planner');
        $this->assertTrue($group->model->is($planner));
        $this->assertTrue($group->items[0]->item->is($dedicated));
        $this->assertTrue($group->shared[0]->item->is($shared));
        $this->assertSame('met Studbook', $group->shared[0]->detail);
        $this->assertSame('240.00', (string) $group->sharedTotal());
    }

    public function test_a_project_with_only_shared_costs_still_shows_up(): void
    {
        $planner = Project::factory()->create(['name' => 'Pasture Planner']);
        $studbook = Project::factory()->create(['name' => 'Studbook']);
        $this->serverFor([$planner, $studbook], '20.00');

        $groups = collect($this->overview()->byProject);

        $this->assertSame(['Gedeeld' => '240.00', 'Pasture Planner' => '0', 'Studbook' => '0'], $this->totals($groups->all()));
        $this->assertSame('240.00', (string) $groups->firstWhere('label', 'Studbook')->sharedTotal());
    }

    public function test_per_client(): void
    {
        $carter = Client::factory()->create(['name' => 'Carter Cycles']);
        $bakery = Client::factory()->create(['name' => 'Meadow Bakery']);
        $webshop = Project::factory()->create(['client_id' => $carter->id]);
        $site = Project::factory()->create(['client_id' => $bakery->id]);
        $own = Project::factory()->create(['client_id' => null]);
        $this->serverFor([$webshop], '10.00');
        $this->serverFor([$webshop, $site], '20.00');
        $this->serverFor([$own], '1.00');
        Domain::factory()->create(['client_id' => $bakery->id, 'cost_amount' => '12.00', 'cost_period' => CostPeriod::Year]);
        Subscription::factory()->create(['project_id' => $webshop->id, 'cost_amount' => '6.00', 'cost_period' => CostPeriod::Year]);
        Subscription::factory()->create(['project_id' => $webshop->id, 'client_id' => $bakery->id, 'cost_amount' => '5.00', 'cost_period' => CostPeriod::Year]);

        $overview = $this->overview();

        $this->assertSame(['Gedeeld' => '240.00', 'Carter Cycles' => '126.00', 'Meadow Bakery' => '17.00', 'Eigen' => '12.00'], $this->totals($overview->byClient));
        $this->assertSame('Carter Cycles, Meadow Bakery', collect($overview->byClient)->firstWhere('label', 'Gedeeld')->items[0]->detail);
        $this->assertSame('met Meadow Bakery', collect($overview->byClient)->firstWhere('label', 'Carter Cycles')->shared[0]->detail);
    }

    public function test_a_server_for_a_client_and_for_ourselves_is_shared_with_eigen(): void
    {
        $webshop = Project::factory()->create(['client_id' => Client::factory()->create(['name' => 'Carter Cycles'])->id]);
        $own = Project::factory()->create(['client_id' => null]);
        $this->serverFor([$webshop, $own], '10.00');

        $groups = collect($this->overview()->byClient);

        $this->assertSame('Carter Cycles, Eigen', $groups->firstWhere('label', 'Gedeeld')->items[0]->detail);
        $this->assertSame('met Carter Cycles', $groups->firstWhere('label', 'Eigen')->shared[0]->detail);
        $this->assertNull($groups->firstWhere('label', 'Eigen')->model);
    }

    public function test_a_monthly_amount_that_does_not_renew_by_itself_is_to_be_decided_from_its_expiry_month(): void
    {
        Server::factory()->create(['cost_amount' => '10.00', 'cost_period' => CostPeriod::Month, 'auto_renews' => false, 'expires_on' => '2027-06-30', 'provider_id' => Provider::factory()->create()->id]);

        $overview = $this->overview();

        $this->assertSame('0', (string) $overview->month('2026-10')->decide(), 'The running contract pays October.');
        $this->assertSame('0', (string) $overview->month('2027-05')->decide());
        $this->assertSame('10.00', (string) $overview->month('2027-06')->decide());
        $this->assertSame('10.00', (string) $overview->month('2027-09')->decide());
        $this->assertSame('120.00', (string) CostOverview::total($overview->months));
    }

    public function test_an_expired_monthly_amount_runs_on_and_is_expired(): void
    {
        $server = Server::factory()->create(['cost_amount' => '14.95', 'cost_period' => CostPeriod::Month, 'expires_on' => '2026-08-01', 'provider_id' => Provider::factory()->create()->id]);

        $overview = $this->overview();

        $this->assertCount(1, $overview->expired);
        $this->assertTrue($overview->expired[0]->is($server));
        $this->assertSame('14.95', (string) $overview->months[0]->total());
        $this->assertSame('14.95', (string) $overview->months[11]->total());
    }

    public function test_a_stopped_monthly_amount_counts_its_expiry_month_also_on_the_first(): void
    {
        Subscription::factory()->create(['cost_amount' => '4.00', 'cost_period' => CostPeriod::Month, 'expires_on' => '2026-12-01', 'renewal_stopped_at' => now()]);
        Subscription::factory()->create(['cost_amount' => '1.00', 'cost_period' => CostPeriod::Month, 'expires_on' => '2028-06-01', 'renewal_stopped_at' => now()]);

        $overview = $this->overview();

        $this->assertSame('5.00', (string) $overview->month('2026-12')->total());
        $this->assertSame('1.00', (string) $overview->month('2027-01')->total());
        $this->assertSame('5.00', (string) $overview->month('2026-10')->total());
        $this->assertSame('1.00', (string) $overview->month('2027-09')->total(), 'Never past the twelve months.');
    }

    public function test_something_only_for_archived_projects_or_clients_is_pointed_out(): void
    {
        $archived = Project::factory()->create(['status' => ProjectStatus::Archived]);
        $active = Project::factory()->create();
        $forArchived = $this->serverFor([$archived], '5.00');
        $this->serverFor([$archived, $active], '5.00');
        $oldClient = Domain::factory()->create(['client_id' => Client::factory()->create(['archived_at' => now()])->id, 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year]);
        Domain::factory()->create(['client_id' => null, 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year]);

        $archivedItems = $this->overview()->archived;

        $this->assertCount(2, $archivedItems);
        $this->assertTrue($archivedItems[0]->is($oldClient) || $archivedItems[1]->is($oldClient));
        $this->assertTrue($archivedItems[0]->is($forArchived) || $archivedItems[1]->is($forArchived));
    }

    public function test_two_owners_with_the_same_name_are_told_apart(): void
    {
        $first = Project::factory()->create(['name' => 'Website']);
        $second = Project::factory()->create(['name' => 'Website']);
        $this->serverFor([$first, $second], '5.00');

        $groups = collect($this->overview()->byProject);

        $this->assertSame('Website, Website', $groups->firstWhere('label', 'Gedeeld')->items[0]->detail);
        $this->assertSame(['met Website', 'met Website'], $groups->where('label', 'Website')->map(fn (CostGroup $group): string => $group->shared[0]->detail)->values()->all());
    }

    public function test_the_quarter_at_its_end_and_at_the_start_of_a_new_one(): void
    {
        Date::setTestNow('2026-12-31 12:00:00');
        $this->assertSame(['2026-12'], array_map(fn (CostMonth $month): string => $month->key(), $this->overview()->range(CostRange::Quarter)));

        Date::setTestNow('2027-01-01 12:00:00');
        $this->assertSame(['2027-01', '2027-02', '2027-03'], array_map(fn (CostMonth $month): string => $month->key(), $this->overview()->range(CostRange::Quarter)));
    }

    public function test_a_server_with_only_an_expiry_date_has_a_contract_and_can_miss_its_amount(): void
    {
        Server::factory()->create(['cost_amount' => null, 'provider_id' => null, 'expires_on' => '2027-01-01']);
        Server::factory()->create(['cost_amount' => null, 'provider_id' => null, 'renewal_stopped_at' => now()]);
        Server::factory()->create(['cost_amount' => null, 'provider_id' => null]);

        $this->assertSame(1, $this->overview()->missing()[Server::class]);
        $this->assertSame(2, Server::query()->withContract()->count());
    }

    public function test_the_group_and_the_unpriced_items_of_one_project_or_client(): void
    {
        $bakery = Client::factory()->create(['name' => 'Meadow Bakery']);
        $webshop = Project::factory()->create(['name' => 'Webshop', 'client_id' => $bakery->id]);
        $other = Project::factory()->create(['name' => 'Ander']);
        $dedicated = $this->serverFor([$webshop], '10.00');
        $shared = $this->serverFor([$webshop, $other], '20.00');
        $unpriced = Server::factory()->create(['provider_id' => Provider::factory()->create()->id, 'cost_amount' => null]);
        $environment = $webshop->environments()->first();
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $unpriced->id, 'role' => ServerRole::Database]);
        $lonely = Project::factory()->create();

        $overview = $this->overview();
        $group = $overview->groupFor($webshop);

        $this->assertTrue($group->items[0]->item->is($dedicated));
        $this->assertTrue($group->shared[0]->item->is($shared));
        $this->assertSame('120.00', (string) $group->total);
        $this->assertSame(['Server' => 1], collect($overview->unpricedFor($webshop))->countBy(fn ($item): string => class_basename($item))->all());
        $this->assertTrue($overview->unpricedFor($bakery)[0]->is($unpriced), 'Through its project.');
        $this->assertSame([], $overview->unpricedFor($other));

        $empty = $overview->groupFor($lonely);
        $this->assertSame('0', (string) $empty->total);
        $this->assertSame([], $empty->items);
        $this->assertTrue($empty->model->is($lonely));
    }

    public function test_the_internet_line_of_a_network_counts_for_the_projects_of_its_servers(): void
    {
        $isp = Provider::factory()->create(['name' => 'Vodafone']);
        $office = Network::factory()->create(['name' => 'Main office', 'provider_id' => $isp->id, 'cost_amount' => '49.50', 'cost_period' => CostPeriod::Month, 'expires_on' => '2027-03-01']);
        $home = Network::factory()->create(['name' => 'Branch office', 'cost_amount' => '40.00', 'cost_period' => CostPeriod::Month]);
        $wiki = Project::factory()->create(['name' => 'Office wiki']);
        $tools = Project::factory()->create(['name' => 'Internal tools']);
        $this->serverFor([$wiki], '0.00')->update(['network_id' => $office->id]);
        $this->serverFor([$tools], '0.00')->update(['network_id' => $office->id]);

        $overview = $this->overview();

        $this->assertSame('1074.00', (string) $overview->yearly);
        $this->assertSame('89.50', (string) $overview->months[0]->total());
        $this->assertSame(['Vodafone' => '594.00', 'Geen leverancier' => '480.00'], $this->totals($overview->byProvider));
        // Its servers run two projects: the line is shared between them.
        $this->assertSame('Internal tools, Office wiki', collect($overview->byProject)->firstWhere('label', 'Gedeeld')->items[0]->detail);
        $this->assertTrue(collect($overview->byProject)->firstWhere('label', 'Geen project')->items[0]->item->is($home));
    }

    public function test_a_line_for_projects_of_several_clients_is_shared_and_never_only_for_archived(): void
    {
        $office = Network::factory()->create(['provider_id' => Provider::factory()->create()->id, 'cost_amount' => '49.50', 'cost_period' => CostPeriod::Month]);
        $a = Project::factory()->create(['client_id' => Client::factory()->create(['name' => 'Client A'])->id, 'status' => ProjectStatus::Archived]);
        $b = Project::factory()->create(['client_id' => Client::factory()->create(['name' => 'Client B'])->id, 'status' => ProjectStatus::Archived]);
        $this->serverFor([$a], '0.00')->update(['network_id' => $office->id]);
        $this->serverFor([$b], '0.00')->update(['network_id' => $office->id]);

        $overview = $this->overview();

        $this->assertSame('Client A, Client B', collect($overview->byClient)->firstWhere('label', 'Gedeeld')->items[0]->detail);
        $this->assertSame('met Client B', collect($overview->byClient)->firstWhere('label', 'Client A')->shared[0]->detail);
        // Its projects are archived, but the office still needs its internet.
        $this->assertFalse(collect($overview->archived)->contains(fn ($item): bool => $item->is($office)));
    }

    public function test_a_network_without_a_line_of_its_own_has_no_contract(): void
    {
        $lan = Network::factory()->create(['provider_id' => null, 'cost_amount' => null]);
        $line = Network::factory()->create(['provider_id' => Provider::factory()->create()->id, 'cost_amount' => null]);

        $this->assertFalse($lan->hasContract());
        $this->assertTrue($line->hasContract());
        $this->assertSame(1, $this->overview()->missing()[Network::class]);
    }

    public function test_it_is_computed_once_per_day(): void
    {
        Subscription::factory()->create(['cost_amount' => '6.80', 'cost_period' => CostPeriod::Month]);
        $costs = app(RecurringCosts::class);

        $this->assertSame($costs->overview(), $costs->overview());
    }

    public function test_payments_know_whether_they_are_the_open_renewal(): void
    {
        Domain::factory()->create(['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-03', 'renewal_period_months' => 6]);

        $payments = collect($this->overview()->months)->flatMap(fn (CostMonth $month): array => $month->payments);

        $this->assertSame([true, false], $payments->map(fn (Payment $payment): bool => $payment->isPending())->all());
    }

    private function overview(): CostOverview
    {
        $this->app->forgetScopedInstances();

        return app(RecurringCosts::class)->overview();
    }

    /**
     * @param  list<Project>  $projects
     */
    private function serverFor(array $projects, string $monthly): Server
    {
        $server = Server::factory()->create(['cost_amount' => $monthly, 'cost_period' => CostPeriod::Month]);

        foreach ($projects as $project) {
            $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
            EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        }

        return $server;
    }

    /**
     * @param  list<CostGroup>  $groups
     * @return array<string, string>
     */
    private function totals(array $groups): array
    {
        return collect($groups)->mapWithKeys(fn (CostGroup $group): array => [$group->label => (string) $group->total])->all();
    }
}
