<?php

namespace Tests\Feature\Filament;

use App\Enums\CostPeriod;
use App\Enums\ServerRole;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * The "Costs" section on the page of a project and of a client.
 */
class OwnerCostsTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_a_project_shows_what_it_costs_what_it_shares_and_what_has_no_amount(): void
    {
        $this->signIn();
        $studbook = Project::factory()->create(['name' => 'Studbook']);
        $planner = Project::factory()->create(['name' => 'Pasture Planner']);
        $this->server('herd-app', [$studbook], '34.95');
        $this->server('herd-db', [$studbook, $planner], '24.95');
        $this->server('herd-storage', [$studbook], null);
        $domain = Domain::factory()->create(['name' => 'studbook.test', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year]);
        Hostname::factory()->create(['domain_id' => $domain->id, 'environment_id' => $studbook->environments()->first()->id]);
        Subscription::factory()->create(['name' => 'Postmark', 'project_id' => $studbook->id, 'cost_amount' => '15.00', 'cost_period' => CostPeriod::Month]);

        $this->get(ProjectResource::getUrl('view', ['record' => $studbook]))
            ->assertOk()
            ->assertSeeInOrder([
                'Kosten', 'Per jaar', '€ 609,35', 'gemiddeld € 50,78 per maand',
                'Gedeeld met anderen', '€ 299,40',
                'herd-app', '€ 34,95 per maand', '€ 419,40',
                'Postmark', '€ 180,00',
                'studbook.test', '€ 9,95',
                'Gedeeld met anderen', 'herd-db', 'met Pasture Planner', '€ 299,40',
                'Nog zonder bedrag', 'herd-storage',
            ])
            ->assertSee(e(SubscriptionResource::getUrl('create', ['project' => $studbook->id])), false);
    }

    public function test_a_client_shows_what_is_for_it_through_its_projects_too(): void
    {
        $this->signIn();
        $bakery = Client::factory()->create(['name' => 'Meadow Bakery']);
        $webshop = Project::factory()->create(['client_id' => $bakery->id]);
        $this->server('web-01', [$webshop], '7.59');
        Domain::factory()->create(['name' => 'meadowbakery.test', 'client_id' => $bakery->id, 'cost_amount' => '12.99', 'cost_period' => CostPeriod::Year]);
        Subscription::factory()->create(['name' => 'Plausible', 'client_id' => $bakery->id, 'cost_amount' => '90.00', 'cost_period' => CostPeriod::Year]);

        $this->get(ClientResource::getUrl('view', ['record' => $bakery]))
            ->assertOk()
            ->assertSeeInOrder(['Kosten', 'Per jaar', '€ 194,07', 'web-01', 'Plausible', 'meadowbakery.test'])
            ->assertDontSee('Gedeeld met anderen')
            ->assertSee(e(SubscriptionResource::getUrl('create', ['client' => $bakery->id])), false);
    }

    public function test_only_shared_costs_read_as_such_not_as_nothing(): void
    {
        $this->signIn();
        $studbook = Project::factory()->create(['name' => 'Studbook']);
        $planner = Project::factory()->create(['name' => 'Pasture Planner']);
        $this->server('herd-db', [$studbook, $planner], '10.00');

        $this->get(ProjectResource::getUrl('view', ['record' => $planner]))
            ->assertSeeInOrder(['Per jaar', 'alleen gedeeld', 'Gedeeld met anderen', '€ 120,00', 'herd-db', 'met Studbook'])
            ->assertDontSee('gemiddeld € 0,00 per maand');
    }

    public function test_an_archived_client_and_a_server_shared_between_clients(): void
    {
        $this->signIn();
        $old = Client::factory()->create(['name' => 'Oud', 'archived_at' => now()]);
        $new = Client::factory()->create(['name' => 'Nieuw']);
        $this->server('shared-01', [Project::factory()->create(['client_id' => $old->id]), Project::factory()->create(['client_id' => $new->id])], '5.00');

        $this->get(ClientResource::getUrl('view', ['record' => $old]))
            ->assertOk()
            ->assertSeeInOrder(['Gedeeld met anderen', '€ 60,00', 'shared-01', 'met Nieuw']);
    }

    public function test_without_any_cost_it_says_so(): void
    {
        $this->signIn();
        $project = Project::factory()->create();

        $this->get(ProjectResource::getUrl('view', ['record' => $project]))
            ->assertOk()
            ->assertSee('Er staat nog niets met een bedrag op naam van dit project.');
    }

    public function test_adding_a_subscription_from_a_project_or_client_fills_it_in(): void
    {
        $this->signIn();
        $project = Project::factory()->create();
        $client = Client::factory()->create();

        Livewire::withQueryParams(['project' => (string) $project->id])
            ->test(CreateSubscription::class)
            ->assertSchemaStateSet(['project_id' => $project->id, 'client_id' => null]);

        Livewire::withQueryParams(['client' => (string) $client->id])
            ->test(CreateSubscription::class)
            ->assertSchemaStateSet(['client_id' => $client->id, 'project_id' => null]);

        Livewire::withQueryParams(['project' => '999'])
            ->test(CreateSubscription::class)
            ->assertSchemaStateSet(['project_id' => null]);
    }

    /**
     * @param  list<Project>  $projects
     */
    private function server(string $name, array $projects, ?string $monthly): Server
    {
        $server = Server::factory()->create([
            'name' => $name,
            'provider_id' => Provider::factory()->create()->id,
            'cost_amount' => $monthly,
            'cost_period' => $monthly === null ? null : CostPeriod::Month,
        ]);

        foreach ($projects as $project) {
            $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
            EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        }

        return $server;
    }
}
