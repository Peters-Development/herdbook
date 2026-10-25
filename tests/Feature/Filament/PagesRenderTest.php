<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Costs;
use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\CredentialReferences\CredentialReferenceResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Check;
use App\Models\Client;
use App\Models\CredentialReference;
use App\Models\Document;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Subscription;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * Every page of every resource renders, with a record in it.
 */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    /** @return array<string, array{class-string<resource>, class-string<Model>}> */
    public static function resources(): array
    {
        return [
            'projects' => [ProjectResource::class, Project::class],
            'servers' => [ServerResource::class, Server::class],
            'networks' => [NetworkResource::class, Network::class],
            'domains' => [DomainResource::class, Domain::class],
            'hostnames' => [HostnameResource::class, Hostname::class],
            'providers' => [ProviderResource::class, Provider::class],
            'clients' => [ClientResource::class, Client::class],
            'credentials' => [CredentialReferenceResource::class, CredentialReference::class],
            'documents' => [DocumentResource::class, Document::class],
            'incidents' => [IncidentResource::class, Incident::class],
            'maintenance' => [MaintenanceResource::class, Maintenance::class],
            'checks' => [CheckResource::class, Check::class],
            'subscriptions' => [SubscriptionResource::class, Subscription::class],
        ];
    }

    /**
     * @param  class-string<resource>  $resource
     * @param  class-string<Model>  $model
     */
    #[DataProvider('resources')]
    public function test_every_page_renders(string $resource, string $model): void
    {
        $this->signIn();
        $this->seedInventory();
        $record = $model::factory()->create();
        $model::factory()->count(2)->create();

        $this->get($resource::getUrl('index'))->assertOk()->assertSee($record->getAttribute($resource::getRecordTitleAttribute()));
        $this->get($resource::getUrl('create'))->assertOk();
        $this->get($resource::getUrl('view', ['record' => $record]))->assertOk();
        $this->get($resource::getUrl('edit', ['record' => $record]))->assertOk();
    }

    public function test_the_dashboard_renders(): void
    {
        $this->signIn();

        $this->get('/')->assertOk();
    }

    public function test_the_cost_page_renders_with_everything_linked(): void
    {
        $this->signIn();
        $this->seedInventory();
        Server::query()->update(['cost_amount' => '5.00', 'cost_period' => 'month']);
        Domain::query()->update(['cost_amount' => '9.95', 'cost_period' => 'year']);

        $this->get(Costs::getUrl())->assertOk()->assertSee('Per leverancier');
    }

    /**
     * Several of everything, linked: lazy loading only fails when a list
     * holds more than one model, and closures only run on related rows.
     */
    private function seedInventory(): void
    {
        $provider = Provider::factory()->registrar()->create();
        foreach (range(1, 3) as $i) {
            $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['client_id' => Client::factory()->create()->id])->id]);
            $network = Network::factory()->create(['provider_id' => $provider->id]);
            $network->addresses()->create(['address' => "192.168.1.{$i}", 'name' => "Apparaat {$i}"]);
            $server = Server::factory()->create(['provider_id' => $provider->id, 'network_id' => $network->id, 'private_ip' => "192.168.1.1{$i}"]);
            $network->incidents()->attach(Incident::factory()->create());
            EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => 'app']);
            $domain = Domain::factory()->create(['registrar_id' => $provider->id, 'dns_provider_id' => $provider->id]);
            $hostname = Hostname::factory()->create(['domain_id' => $domain->id, 'environment_id' => $environment->id]);
            $check = Check::factory()->create(['hostname_id' => $hostname->id]);
            $check->events()->create(['from_state' => 'up', 'to_state' => 'down', 'occurred_at' => now()]);
            $incident = Incident::factory()->create();
            $incident->projects()->attach($environment->project_id);
            $incident->servers()->attach($server->id);
            $incident->actions()->create(['description' => "Actie {$i}"]);
            Document::factory()->create()->projects()->attach($environment->project_id);
            CredentialReference::factory()->create()->servers()->attach($server->id);
            $maintenance = Maintenance::factory()->create();
            $maintenance->servers()->attach($server->id);
            $maintenance->projects()->attach($environment->project_id);
            Maintenance::factory()->planned()->create()->servers()->attach($server->id);
            Subscription::factory()->create(['provider_id' => $provider->id, 'project_id' => $environment->project_id]);
        }
    }
}
