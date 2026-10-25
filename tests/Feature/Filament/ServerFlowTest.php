<?php

namespace Tests\Feature\Filament;

use App\Enums\ProviderKind;
use App\Enums\RenewalStatus;
use App\Enums\ServerRole;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Provider;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ServerFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_a_server_is_created_with_what_it_depends_on(): void
    {
        $this->signIn();
        $storage = Server::factory()->create(['name' => 'storage']);

        Livewire::test(CreateServer::class)
            ->fillForm([
                'name' => 'app',
                'status' => 'active',
                'public_ipv4' => '203.0.113.10',
                'ssh_port' => 22,
                'renewal_period_months' => 12,
                'dependencies' => [
                    ['depends_on_id' => $storage->id, 'kind' => 'NFS', 'notes' => null],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $app = Server::query()->where('name', 'app')->sole();
        $this->assertTrue($app->dependencies()->sole()->dependsOn->is($storage));
    }

    public function test_an_invalid_ip_address_is_refused(): void
    {
        $this->signIn();

        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'app', 'status' => 'active', 'public_ipv4' => '999.1.1.1', 'ssh_port' => 22, 'renewal_period_months' => 12])
            ->call('create')
            ->assertHasFormErrors(['public_ipv4']);
    }

    public function test_a_server_cannot_depend_on_itself(): void
    {
        $this->signIn();
        $server = Server::factory()->create();

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['dependencies' => [['depends_on_id' => $server->id, 'kind' => 'NFS', 'notes' => null]]])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame(0, $server->dependencies()->count());
    }

    public function test_the_view_page_shows_what_goes_down_with_the_server(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        $environment = Environment::factory()->create();
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::App]);

        $this->get(ServerResource::getUrl('view', ['record' => $server]))
            ->assertOk()
            ->assertSee('Geraakt bij uitval')
            ->assertSee(e($environment->full_name).": draait op {$server->name} (App)", false);
    }

    public function test_a_server_of_our_own_has_no_contract_and_is_reached_over_its_private_address(): void
    {
        $this->signIn();
        $own = Server::factory()->create(['name' => 'office-01', 'public_ipv4' => null, 'private_ip' => '192.168.60.10', 'ssh_user' => 'admin']);
        $rented = Server::factory()->for(Provider::factory())->create(['name' => 'vps-01']);

        $this->assertFalse($own->hasContract());
        $this->assertTrue($rented->hasContract());
        $this->assertTrue(Server::factory()->create(['expires_on' => '2027-01-01'])->hasContract(), 'A date is a contract, also without a provider.');

        $this->get(ServerResource::getUrl('view', ['record' => $own]))
            ->assertOk()
            ->assertSee('ssh admin@192.168.60.10')
            ->assertDontSee(['Geen datum', 'Afspraak']);
        Livewire::test(ViewServer::class, ['record' => $own->getRouteKey()])->assertActionHidden('stopRenewal');
        $this->get(ServerResource::getUrl('view', ['record' => $rented]))->assertSee(['Geen datum', 'Afspraak']);

        Livewire::test(ListServers::class)
            ->assertTableColumnStateSet('renewal_status', null, $own)
            ->assertTableColumnStateSet('renewal_status', RenewalStatus::Unknown, $rented)
            ->filterTable('renewal_status', RenewalStatus::Unknown->value)
            ->assertCanSeeTableRecords([$rented])
            ->assertCanNotSeeTableRecords([$own]);
    }

    public function test_the_contract_fields_come_with_a_provider(): void
    {
        $this->signIn();
        $provider = Provider::factory()->create(['kinds' => [ProviderKind::Hosting]]);

        Livewire::test(CreateServer::class)
            ->assertFormFieldHidden('expires_on')
            ->fillForm(['provider_id' => $provider->id])
            ->assertFormFieldVisible('expires_on');

        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'office-01', 'status' => 'active', 'private_ip' => '192.168.60.10', 'ssh_port' => 22])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(12, Server::query()->where('name', 'office-01')->sole()->renewal_period_months);
    }

    public function test_emptying_the_date_of_a_server_without_a_provider_is_saved(): void
    {
        $this->signIn();
        $server = Server::factory()->create(['expires_on' => '2027-01-01']);

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['expires_on' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($server->fresh()->expires_on);
    }

    public function test_a_server_is_found_by_its_ip_address(): void
    {
        $this->signIn();
        $server = Server::factory()->create(['public_ipv4' => '203.0.113.77']);

        $results = ServerResource::getGlobalSearchResults('203.0.113.77');

        $this->assertCount(1, $results);
        $this->assertSame($server->name, (string) $results->first()->title);
    }

    public function test_global_search_over_several_servers_loads_their_providers_in_one_go(): void
    {
        $this->signIn();
        $provider = Provider::factory()->create();
        Server::factory()->count(2)->sequence(['name' => 'acme-app'], ['name' => 'acme-storage'])->create(['provider_id' => $provider->id]);

        $results = ServerResource::getGlobalSearchResults('acme');

        $this->assertCount(2, $results);
        $this->assertSame($provider->name, $results->first()->details['Leverancier']);
    }

    public function test_a_dependency_typed_in_another_case_counts_as_the_same_one(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        $storage = Server::factory()->create();

        Livewire::test(EditServer::class, ['record' => $server->getRouteKey()])
            ->fillForm(['dependencies' => [
                ['depends_on_id' => $storage->id, 'kind' => 'NFS', 'notes' => null],
                ['depends_on_id' => $storage->id, 'kind' => 'nfs', 'notes' => null],
            ]])
            ->call('save')
            ->assertHasFormErrors(['dependencies']);
    }

    public function test_a_server_with_two_roles_in_one_environment_counts_one_environment(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        $environment = Environment::factory()->create();
        foreach ([ServerRole::App, ServerRole::Database] as $role) {
            EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => $role]);
        }

        Livewire::test(ListServers::class)
            ->assertTableColumnStateSet('environments_count', 1, $server);
    }
}
