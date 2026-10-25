<?php

namespace Tests\Feature\Filament;

use App\Enums\IncidentStatus;
use App\Enums\ProviderKind;
use App\Enums\ServerRole;
use App\Enums\TimelineRange;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Providers\Pages\EditProvider;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Support\ActivityPresenter;
use App\Models\Document;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Incident;
use App\Models\Network;
use App\Models\NetworkAddress;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Rules\Subnet;
use App\Services\Health\ProjectIncidents;
use App\Services\Health\ProjectTimeline;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class NetworkFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_a_network_is_created_with_its_addresses(): void
    {
        $this->signIn();
        $isp = Provider::factory()->create(['name' => 'Vodafone', 'kinds' => [ProviderKind::Internet->value]]);

        Livewire::test(CreateNetwork::class)
            ->fillForm([
                'name' => 'Main office',
                'provider_id' => $isp->id,
                'subnet' => '192.168.60.0/24',
                'gateway' => '192.168.60.1',
                'addresses' => [
                    ['address' => '192.168.60.1', 'name' => 'Router', 'mac_address' => '02:00:5e:12:34:56', 'notes' => null],
                    ['address' => '192.168.60.20', 'name' => 'NAS', 'mac_address' => null, 'notes' => 'Backups'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = Network::query()->sole();
        $this->assertSame(['Main office', '192.168.60.0/24', 'Vodafone'], [$network->name, $network->subnet, $network->provider->name]);
        $this->assertSame(['192.168.60.1', '192.168.60.20'], $network->addresses()->orderBy('id')->pluck('address')->all());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function subnets(): array
    {
        return [
            'IPv4' => ['192.168.60.0/24', true],
            'IPv6' => ['fd00::/64', true],
            'one address' => ['10.0.0.1/32', true],
            'prefix with a leading zero' => ['192.168.60.0/024', false],
            'no prefix' => ['192.168.60.0', false],
            'prefix too long' => ['192.168.60.0/33', false],
            'IPv6 prefix too long' => ['fd00::/129', false],
            'not an address' => ['office/24', false],
            'spaces' => ['192.168.60.0 /24', false],
        ];
    }

    #[DataProvider('subnets')]
    public function test_a_subnet_is_written_in_cidr_notation(string $subnet, bool $valid): void
    {
        $this->assertSame($valid, Subnet::isValid($subnet));
    }

    public function test_an_invalid_subnet_or_mac_address_is_refused(): void
    {
        $this->signIn();

        Livewire::test(CreateNetwork::class)
            ->fillForm([
                'name' => 'Main office',
                'subnet' => '192.168.60.0',
                'addresses' => [['address' => '192.168.60.1', 'name' => 'Router', 'mac_address' => 'router', 'notes' => null]],
            ])
            ->call('create')
            ->assertHasFormErrors(['subnet', 'addresses.0.mac_address']);

        $this->assertSame(0, Network::query()->count());
    }

    public function test_the_same_address_twice_is_refused_however_it_is_written(): void
    {
        $this->signIn();

        Livewire::test(CreateNetwork::class)
            ->fillForm([
                'name' => 'Main office',
                'addresses' => [
                    ['address' => 'fd00::1', 'name' => 'Router', 'mac_address' => null, 'notes' => null],
                    ['address' => 'FD00:0:0::1', 'name' => 'Printer', 'mac_address' => null, 'notes' => null],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['addresses']);
    }

    public function test_an_invalid_ip_address_reads_well(): void
    {
        $this->signIn();

        Livewire::test(CreateNetwork::class)
            ->fillForm(['name' => 'Main office', 'addresses' => [['address' => '192.168.1.300', 'name' => 'Router', 'mac_address' => null, 'notes' => null]]])
            ->call('create')
            ->assertHasFormErrors(['addresses.0.address' => 'Het veld IP-adres moet een geldig IP-adres zijn.']);
    }

    public function test_the_edit_form_shows_the_subnet_as_it_was_saved(): void
    {
        $this->signIn();
        $network = Network::factory()->create();

        Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
            ->fillForm(['subnet' => '192.168.60.7/24'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['subnet' => '192.168.60.0/24']);
    }

    public function test_an_address_that_a_server_and_a_device_share_is_marked_whichever_came_first(): void
    {
        $this->signIn();
        $network = Network::factory()->create(['name' => 'Main office']);
        $network->addresses()->create(['address' => '192.168.1.20', 'name' => 'NAS']);
        $server = Server::factory()->create(['name' => 'office-01', 'public_ipv4' => null, 'private_ip' => '192.168.1.20']);

        // The server's form cannot see the devices: it saves.
        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['network_id' => $network->id])
            ->call('save')
            ->assertHasNoFormErrors();

        // Nor does the network's form refuse it: the page marks both.
        Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
            ->fillForm(['name' => 'Branch office'])
            ->call('save')
            ->assertHasNoFormErrors();

        $book = $network->fresh()->addressBook();
        $this->assertSame([true, true], $book->pluck('duplicate')->all());
        $this->get(NetworkResource::getUrl('view', ['record' => $network]))->assertOk()->assertSee('Dubbel adres');
    }

    public function test_addresses_mac_addresses_and_subnets_are_stored_one_way(): void
    {
        $this->signIn();

        Livewire::test(CreateNetwork::class)
            ->fillForm([
                'name' => 'Main office',
                'subnet' => '192.168.60.7/24',
                'addresses' => [
                    ['address' => 'FD00:0:0::1', 'name' => 'Router', 'mac_address' => '02-00-5E-12-34-56', 'notes' => null],
                    ['address' => '192.168.60.20', 'name' => 'NAS', 'mac_address' => '0200.5e12.3457', 'notes' => null],
                    ['address' => '192.168.60.30', 'name' => 'Printer', 'mac_address' => '02005E123458', 'notes' => null],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = Network::query()->sole();
        $this->assertSame('192.168.60.0/24', $network->subnet);
        $this->assertSame(['fd00::1', '192.168.60.20', '192.168.60.30'], $network->addresses()->orderBy('id')->pluck('address')->all());
        $this->assertSame(['02:00:5e:12:34:56', '02:00:5e:12:34:57', '02:00:5e:12:34:58'], $network->addresses()->orderBy('id')->pluck('mac_address')->all());
        $this->assertSame('fd00::/64', Subnet::normalize('fd00::1234/64'));
    }

    public function test_a_server_is_put_in_a_network_from_its_own_form(): void
    {
        $this->signIn();
        $network = Network::factory()->create(['name' => 'Main office']);
        $server = Server::factory()->create(['public_ipv4' => null, 'private_ip' => '192.168.1.10']);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['network_id' => $network->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($server->fresh()->network->is($network));
        $this->get(ServerResource::getUrl('view', ['record' => $server]))
            ->assertOk()
            ->assertSee(['Main office', NetworkResource::getUrl('view', ['record' => $network])], false);
    }

    public function test_the_view_page_lists_servers_and_devices_by_address_and_what_goes_down(): void
    {
        $this->signIn();
        $network = Network::factory()->create(['subnet' => '192.168.1.0/24']);
        $local = Server::factory()->create(['name' => 'office-01', 'network_id' => $network->id, 'private_ip' => '192.168.1.10']);
        Server::factory()->create(['name' => 'zonder-ip', 'network_id' => $network->id, 'private_ip' => null]);
        $network->addresses()->createMany([
            ['address' => '192.168.1.9', 'name' => 'Printer'],
            ['address' => '192.168.1.1', 'name' => 'Router', 'mac_address' => '02:00:5e:12:34:56'],
            ['address' => '10.0.0.5', 'name' => 'Verdwaald', 'notes' => 'Oude switch'],
        ]);
        $environment = Environment::factory()->create();
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $local->id, 'role' => ServerRole::App]);
        $backup = Server::factory()->create(['name' => 'backup-01']);
        $other = Environment::factory()->create();
        EnvironmentServer::create(['environment_id' => $other->id, 'server_id' => $backup->id, 'role' => ServerRole::Storage]);
        ServerDependency::create(['server_id' => $backup->id, 'depends_on_id' => $local->id, 'kind' => 'rsync']);

        $this->get(NetworkResource::getUrl('view', ['record' => $network]))
            ->assertOk()
            // In the order of their address (as numbers), a server without one last.
            ->assertSeeInOrder(['10.0.0.5', '192.168.1.1', '192.168.1.9', '192.168.1.10', 'zonder-ip'])
            ->assertSee([
                ServerResource::getUrl('view', ['record' => $local]),
                '02:00:5e:12:34:56',
                'Buiten 192.168.1.0/24 · Oude switch',
                'Geen privé-IP',
                "{$environment->full_name}: draait op office-01 (App)",
                "{$other->full_name}: via backup-01 → rsync → office-01",
            ], false);
    }

    public function test_deleting_a_network_keeps_its_servers_and_logs_what_changed(): void
    {
        $this->signIn();
        $network = Network::factory()->create();
        $server = Server::factory()->create(['network_id' => $network->id]);
        $network->addresses()->create(['address' => '192.168.1.1', 'name' => 'Router']);
        $network->documents()->attach(Document::factory()->create());

        Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
            ->assertActionExists(DeleteAction::class, fn (DeleteAction $action): bool => $action->getModalDescription() === 'Hiermee verdwijnen ook: 1 adres. De server in dit netwerk blijft bestaan, zonder netwerk. Gekoppelde incidenten, documenten en credentials blijven bestaan.')
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($network);
        $this->assertNull($server->fresh()->network_id);
        $this->assertSame(0, NetworkAddress::query()->count());
        $this->assertSame(1, Document::query()->count());
        $this->assertTrue(Activity::query()->where('subject_type', 'server')->where('subject_id', $server->id)->where('event', 'updated')->exists());
        $this->assertTrue(Activity::query()->where('subject_type', 'network_address')->where('event', 'deleted')->where('properties->network_id', $network->id)->exists());
    }

    public function test_the_internet_provider_is_one_that_delivers_internet(): void
    {
        $this->signIn();
        Provider::factory()->create(['name' => 'Hoster']);
        $isp = Provider::factory()->create(['name' => 'Vodafone', 'kinds' => [ProviderKind::Internet->value]]);

        Livewire::test(CreateNetwork::class)
            ->assertFormFieldExists('provider_id', fn ($field): bool => array_values($field->getOptions()) === ['Vodafone']);

        $network = Network::factory()->create(['name' => 'Branch office', 'provider_id' => $isp->id]);
        $this->get(ProviderResource::getUrl('view', ['record' => $isp]))->assertOk()->assertSee($network->name);
    }

    public function test_the_search_bar_finds_a_network_by_the_address_of_a_device(): void
    {
        $this->signIn();
        $network = Network::factory()->create(['name' => 'Main office']);
        $network->addresses()->create(['address' => '192.168.1.20', 'name' => 'NAS']);
        Network::factory()->create(['name' => 'Branch office']);

        $results = NetworkResource::getGlobalSearchResults('192.168.1.20');

        $this->assertSame(['Main office'], $results->pluck('title')->map(fn ($title): string => (string) $title)->all());
    }

    public function test_a_document_is_linked_to_a_network(): void
    {
        $this->signIn();
        $network = Network::factory()->create();

        Livewire::test(CreateDocument::class)
            ->fillForm(['title' => 'Netwerkplan', 'kind' => 'architecture', 'body' => 'VLAN 10: office', 'networks' => [$network->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Netwerkplan'], $network->documents()->pluck('title')->all());
    }

    public function test_a_provider_that_delivers_a_line_cannot_be_deleted(): void
    {
        $this->signIn();
        $isp = Provider::factory()->create(['kinds' => [ProviderKind::Internet->value]]);
        Network::factory()->create(['provider_id' => $isp->id]);

        Livewire::test(EditProvider::class, ['record' => $isp->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertModelExists($isp);
    }

    public function test_an_incident_on_the_network_is_one_of_the_projects_running_on_it(): void
    {
        $network = Network::factory()->create();
        $local = Server::factory()->create(['network_id' => $network->id]);
        $production = Environment::factory()->create();
        EnvironmentServer::create(['environment_id' => $production->id, 'server_id' => $local->id, 'role' => ServerRole::App]);
        $elsewhere = Environment::factory()->create();
        EnvironmentServer::create(['environment_id' => $elsewhere->id, 'server_id' => Server::factory()->create()->id, 'role' => ServerRole::App]);
        $incident = Incident::factory()->create(['title' => 'Office internet down', 'status' => IncidentStatus::Open, 'started_at' => now()->subHour()]);
        $network->incidents()->attach($incident);

        $this->assertSame(['Office internet down'], app(ProjectIncidents::class)->open($production->project_id)->pluck('title')->all());
        $this->assertSame([], app(ProjectIncidents::class)->open($elsewhere->project_id)->pluck('title')->all());
        $this->assertSame(['Office internet down'], app(ProjectTimeline::class)->build($production->project, TimelineRange::Day)->incidents->pluck('title')->all());
    }

    public function test_the_servers_list_shows_and_filters_by_network(): void
    {
        $this->signIn();
        $network = Network::factory()->create(['name' => 'Main office']);
        $local = Server::factory()->create(['name' => 'office-01', 'network_id' => $network->id]);
        $vps = Server::factory()->for(Provider::factory()->state(['name' => 'Hoster']))->create(['name' => 'vps']);

        Livewire::test(ListServers::class)
            ->assertSee(['Main office', 'Hoster'])
            ->filterTable('network_id', $network->id)
            ->assertCanSeeTableRecords([$local])
            ->assertCanNotSeeTableRecords([$vps]);
    }

    public function test_the_activity_log_names_the_network_and_the_address(): void
    {
        $this->signIn();
        $network = Network::factory()->create(['name' => 'Main office']);
        $server = Server::factory()->create();
        $server->update(['network_id' => $network->id]);
        $network->addresses()->create(['address' => '192.168.1.1', 'name' => 'Router']);

        $update = Activity::query()->where('subject_type', 'server')->where('event', 'updated')->sole();
        $address = Activity::query()->where('subject_type', 'network_address')->sole();

        $this->assertSame('Netwerk: — → Main office', ActivityPresenter::changes($update));
        $this->assertSame('Aangemaakt · Adres 192.168.1.1 (Router)', ActivityPresenter::description($address, 'network'));
    }
}
