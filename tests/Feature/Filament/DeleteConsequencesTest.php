<?php

namespace Tests\Feature\Filament;

use App\Enums\ServerRole;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Providers\Pages\EditProvider;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Models\Check;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Services\Checks\NetworkProbe;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

class DeleteConsequencesTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, new FakeProbe);
    }

    public function test_a_server_in_use_cannot_be_deleted(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        EnvironmentServer::create(['environment_id' => Environment::factory()->create()->id, 'server_id' => $server->id, 'role' => 'app']);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertModelExists($server);
    }

    public function test_deleting_a_project_pauses_the_checks_of_its_hostnames_and_says_so(): void
    {
        $this->signIn();
        $environment = Environment::factory()->create();
        $check = Check::factory()->create(['hostname_id' => Hostname::factory()->create(['environment_id' => $environment->id])->id]);

        Livewire::test(EditProject::class, ['record' => $environment->project->getRouteKey()])
            ->mountAction(DeleteAction::class)
            ->assertMountedActionModalSee('1 omgeving')
            ->assertMountedActionModalSee('De check op de hostnames gaat op pauze');

        $environment->project->delete();

        $this->assertFalse($check->fresh()->enabled);
    }

    public function test_a_server_in_use_says_so_before_anything_is_confirmed(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        EnvironmentServer::create(['environment_id' => Environment::factory()->create()->id, 'server_id' => $server->id, 'role' => ServerRole::App]);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->mountAction(DeleteAction::class)
            ->assertMountedActionModalSee('nog in gebruik');
    }

    public function test_a_client_with_projects_cannot_be_deleted(): void
    {
        $this->signIn();
        $client = Client::factory()->create();
        Project::factory()->create(['client_id' => $client->id]);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelExists($client);
    }

    public function test_a_client_without_projects_or_domains_can_be_deleted(): void
    {
        $this->signIn();
        $client = Client::factory()->create();

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($client);
    }

    public function test_a_provider_in_use_cannot_be_deleted(): void
    {
        $this->signIn();
        $provider = Provider::factory()->create();
        Server::factory()->create(['provider_id' => $provider->id]);

        Livewire::test(EditProvider::class, ['record' => $provider->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelExists($provider);
    }
}
