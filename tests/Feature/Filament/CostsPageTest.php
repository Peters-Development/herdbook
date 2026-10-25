<?php

namespace Tests\Feature\Filament;

use App\Enums\CostPeriod;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Filament\Pages\Costs;
use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class CostsPageTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-24 12:00:00');
    }

    public function test_it_shows_the_year_this_month_the_quarter_and_the_months_ahead(): void
    {
        $this->signIn();
        $hetzner = Provider::factory()->create(['name' => 'Hetzner']);
        Server::factory()->create(['name' => 'web-01', 'provider_id' => $hetzner->id, 'cost_amount' => '7.59', 'cost_period' => CostPeriod::Month]);
        Domain::factory()->create(['name' => 'pastureplanner.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-03', 'auto_renews' => false]);

        $this->get(Costs::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Per jaar', '€ 101,03', 'wat is opgezegd, telt niet mee', 'Deze maand', '€ 7,59', 'Dit kwartaal', '€ 32,72', 'waarvan € 9,95 te beslissen', 'Komende 12 maanden', '€ 101,03'])
            ->assertSee('Betalingen deze maand')
            ->assertSee('web-01')
            ->assertSeeInOrder(['Per leverancier', 'Hetzner', '€ 91,08', 'Per project', 'Per klant', 'Eigen']);
    }

    public function test_a_tile_or_a_month_picks_the_payments_and_the_same_month_again_goes_back(): void
    {
        $this->signIn();
        Domain::factory()->create(['name' => 'pastureplanner.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-03', 'auto_renews' => false]);

        Livewire::test(Costs::class)
            ->assertDontSee('03-11-2026')
            ->call('select', 'quarter')
            ->assertSet('period', 'quarter')
            ->assertSee('Betalingen dit kwartaal')
            ->assertSee('03-11-2026')
            ->assertSee('Te beslissen')
            ->call('select', '2026-11')
            ->assertSee('Betalingen in november 2026')
            ->assertSee('pastureplanner.test')
            ->call('select', '2026-11')
            ->assertSet('period', 'month');
    }

    public function test_this_months_bar_is_the_tile_this_month_also_from_a_link(): void
    {
        $this->signIn();

        Livewire::test(Costs::class)
            ->call('select', '12-months')
            ->call('select', '2026-10')
            ->assertSet('period', 'month')
            ->assertSee('Betalingen deze maand');

        Livewire::withQueryParams(['period' => '2026-10'])
            ->test(Costs::class)
            ->assertSet('period', 'month')
            ->assertSee('Betalingen deze maand');
    }

    public function test_a_monthly_line_says_how_much_of_it_is_to_be_decided(): void
    {
        $this->signIn();
        Server::factory()->create(['name' => 'herd-db', 'provider_id' => Provider::factory()->create()->id, 'cost_amount' => '10.00', 'cost_period' => CostPeriod::Month, 'auto_renews' => false, 'expires_on' => '2027-06-30']);

        $line = collect(Livewire::test(Costs::class)->call('select', '12-months')->viewData('monthly'))->sole();

        $this->assertSame('€ 120,00', $line['amount']);
        $this->assertSame('waarvan € 40,00 te beslissen', $line['decide']);
        $this->assertSame(['label' => 'Te beslissen', 'color' => 'gray'], $line['badge']);
    }

    public function test_deciding_reads_the_same_now_and_later(): void
    {
        $this->signIn();
        // Due now and not automatic: the renewal status "Decision needed".
        Domain::factory()->create(['name' => 'nu.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-15', 'auto_renews' => false]);

        $renewals = collect(Livewire::test(Costs::class)->call('select', '12-months')->viewData('renewals'));

        $this->assertSame(['label' => 'Te beslissen', 'color' => 'warning'], $renewals->first()['badge']);
    }

    public function test_something_expired_this_month_or_only_for_archived_projects_is_listed(): void
    {
        $this->signIn();
        Domain::factory()->create(['name' => 'net-verlopen.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-10-21']);
        $archived = Project::factory()->create(['status' => ProjectStatus::Archived]);
        Subscription::factory()->create(['name' => 'Old archive storage', 'project_id' => $archived->id, 'cost_amount' => '3.00', 'cost_period' => CostPeriod::Month]);

        $this->get(Costs::getUrl())
            ->assertSee('net-verlopen.test is verlopen op 21-10-2026, en niet verlengd of opgezegd (€ 9,95).')
            ->assertSee('Old archive storage is alleen nog voor wat gearchiveerd is, maar loopt door: € 3,00 per maand.');
    }

    public function test_a_payment_shows_the_renewal_status_only_for_the_renewal_that_is_up_now(): void
    {
        $this->signIn();
        // Expires in 9 days, renews by itself 60 days before: it should have by now.
        Domain::factory()->create(['name' => 'controleren.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-11-02', 'renewal_period_months' => 3]);
        // Far out, renews by itself: nothing to say.
        Domain::factory()->create(['name' => 'rustig.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2027-08-01']);
        // Far out, but someone has to renew it.
        Domain::factory()->create(['name' => 'handmatig.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2027-08-01', 'auto_renews' => false]);

        $page = Livewire::test(Costs::class)->call('select', '12-months');

        $renewals = collect($page->viewData('renewals'))->map(fn (array $line): string => "{$line['name']} {$line['date']} ".($line['badge']['label'] ?? '-'))->all();

        $this->assertSame([
            'controleren.test 02-11-2026 Controleren',
            'controleren.test 02-02-2027 -',
            'controleren.test 02-05-2027 -',
            'handmatig.test 01-08-2027 Te beslissen',
            'rustig.test 01-08-2027 -',
            'controleren.test 02-08-2027 -',
        ], $renewals);
    }

    public function test_a_shared_server_shows_up_with_each_project_but_counts_once(): void
    {
        $this->signIn();
        $server = Server::factory()->create(['name' => 'herd-app', 'cost_amount' => '10.00', 'cost_period' => CostPeriod::Month]);
        foreach (['Pasture Planner', 'Studbook'] as $name) {
            $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['name' => $name])->id]);
            EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        }

        $this->get(Costs::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Per project', 'Gedeeld', '€ 120,00', 'Pasture Planner, Studbook', 'Pasture Planner', 'alleen gedeeld', '+ € 120,00 gedeeld met anderen', 'met Studbook']);
    }

    public function test_a_machine_of_our_own_out_of_use_is_told_to_lose_its_amount(): void
    {
        $this->signIn();
        Server::factory()->create(['name' => 'office-01', 'status' => ServerStatus::Decommissioned, 'cost_amount' => '5.00', 'cost_period' => CostPeriod::Month]);

        $this->get(Costs::getUrl())->assertSee('office-01 is uit gebruik, maar telt nog mee: € 5,00 per maand. Haal het bedrag weg');
    }

    public function test_an_unknown_period_falls_back_to_this_month(): void
    {
        $this->signIn();

        $this->get(Costs::getUrl().'?period=2030-01')->assertOk()->assertSee('Betalingen deze maand');
        $this->get(Costs::getUrl().'?period=nonsense')->assertOk()->assertSee('Betalingen deze maand');
    }

    public function test_what_keeps_the_numbers_from_being_right_is_listed_with_a_link(): void
    {
        $this->signIn();
        Domain::factory()->count(2)->create(['cost_amount' => null]);
        Domain::factory()->create(['name' => 'vergeten.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'expires_on' => '2026-08-01']);
        Server::factory()->create(['name' => 'legacy-01', 'provider_id' => Provider::factory()->create()->id, 'status' => ServerStatus::Decommissioned, 'cost_amount' => '14.95', 'cost_period' => CostPeriod::Month]);
        Subscription::factory()->create(['name' => 'JetBrains', 'cost_amount' => '289.00', 'cost_period' => CostPeriod::Year, 'expires_on' => null]);

        $this->get(Costs::getUrl())
            ->assertOk()
            ->assertSee('Nog regelen')
            ->assertSee('2 domeinen zonder bedrag of periode: ze tellen nergens mee.')
            ->assertSee(e(DomainResource::getUrl('index', ['filters' => ['missing_cost' => ['isActive' => true]]])), false)
            ->assertSee('vergeten.test is verlopen op 01-08-2026')
            ->assertSee('legacy-01 is uit gebruik, maar niet opgezegd: € 14,95 per maand.')
            ->assertSee('JetBrains (€ 289,00 per jaar) heeft geen verloopdatum');
    }

    public function test_an_undated_line_without_a_provider_asks_for_an_internet_provider(): void
    {
        $this->signIn();
        Network::factory()->create(['name' => 'Office', 'cost_amount' => '49.50', 'cost_period' => CostPeriod::Year, 'expires_on' => null]);

        $this->get(Costs::getUrl())->assertSee('Kies een internetprovider om er een in te vullen.');
    }

    public function test_without_any_amount_it_still_renders(): void
    {
        $this->signIn();

        $this->get(Costs::getUrl())
            ->assertOk()
            ->assertSee('€ 0,00')
            ->assertSee('Niets te betalen in deze periode.')
            ->assertDontSee('Nog regelen');
    }

    public function test_a_stopped_subscription_says_so(): void
    {
        $this->signIn();
        Subscription::factory()->create(['name' => 'Old mailbox', 'cost_amount' => '4.00', 'cost_period' => CostPeriod::Month, 'expires_on' => '2026-12-01', 'renewal_stopped_at' => now()]);

        Livewire::test(Costs::class)
            ->assertSee('Old mailbox')
            ->assertSee('Opgezegd');
    }
}
