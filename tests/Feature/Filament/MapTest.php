<?php

namespace Tests\Feature\Filament;

use App\Enums\CheckState;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Filament\Pages\Map;
use App\Models\Check;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Services\Map\InfrastructureMap;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class MapTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_the_map_renders_with_the_demo_data_and_its_script(): void
    {
        $this->signIn();
        $this->seed(DemoSeeder::class);

        $this->get(Map::getUrl())
            ->assertSuccessful()
            ->assertSee(['Kaart', 'Leverancier / netwerk', 'herd-storage', 'office-01', '192.168.1.0/24', 'pastureplanner.test', 'herdbook-map.js'], false);
    }

    public function test_columns_are_linked_from_what_is_needed_to_what_needs_it(): void
    {
        ['storage' => $storage, 'app' => $app, 'production' => $production, 'hostname' => $hostname] = $this->inventory();

        $map = app(InfrastructureMap::class)->build();
        $edges = collect($map['edges'])->map(fn (array $edge): string => "{$edge['from']} > {$edge['to']} ({$edge['kind']}:{$edge['label']})");

        $this->assertContains("provider-{$app->provider_id} > server-{$app->id} (hosts:)", $edges);
        $this->assertContains("server-{$storage->id} > server-{$app->id} (depends:NFS)", $edges);
        $this->assertContains("server-{$app->id} > environment-{$production->id} (runs:)", $edges);
        $this->assertContains("environment-{$production->id} > hostname-{$hostname->id} (points:)", $edges);
        $this->assertContains("hostname-{$hostname->id} > domain-{$hostname->domain_id} (domain:)", $edges);
    }

    public function test_servers_follow_their_environments_and_what_hangs_nowhere_goes_to_the_bottom(): void
    {
        $this->inventory();
        $zebra = Project::factory()->create(['name' => 'Zebra']);
        $zebraProduction = Environment::factory()->for($zebra)->create();
        $zebraServer = Server::factory()->create(['name' => 'aaa-zebra']);
        EnvironmentServer::create(['environment_id' => $zebraProduction->id, 'server_id' => $zebraServer->id, 'role' => ServerRole::App]);
        Server::factory()->create(['name' => 'aaa-los']);

        $servers = collect(app(InfrastructureMap::class)->build()['columns']['servers'])->pluck('label')->all();

        // Alpha's servers first (its project comes first; storage next to the
        // app server that needs it, then staging), Zebra's after, the loose one last.
        $this->assertSame(['app-01', 'opslag-01', 'test-01', 'aaa-zebra', 'aaa-los'], $servers);
    }

    public function test_a_filter_shows_the_chosen_environments_and_what_they_need(): void
    {
        ['project' => $project, 'staging' => $staging] = $this->inventory();
        Server::factory()->create(['name' => 'los-01']);
        Project::factory()->create(['name' => 'Ander']);

        $map = app(InfrastructureMap::class)->build(productionOnly: true, projectId: $project->id);

        $this->assertSame(['app-01', 'opslag-01'], collect($map['columns']['servers'])->pluck('label')->all());
        $this->assertSame(['Production'], collect($map['groups'])->flatMap(fn (array $group) => $group['nodes'])->pluck('label')->all());
        $this->assertNotContains("environment-{$staging->id}", collect($map['edges'])->pluck('to'));
        $this->assertSame(['Alpha'], collect($map['groups'])->pluck('label')->all());
    }

    public function test_archived_projects_stay_off_the_map(): void
    {
        ['project' => $project] = $this->inventory();
        $project->update(['status' => ProjectStatus::Archived]);

        $this->assertSame([], app(InfrastructureMap::class)->build()['groups']);
    }

    public function test_clicking_a_server_shows_what_goes_down_with_it(): void
    {
        $this->signIn();
        ['storage' => $storage, 'app' => $app, 'production' => $production] = $this->inventory();

        $impact = Livewire::test(Map::class)->instance()->impact($storage->id);

        $this->assertSame('opslag-01', $impact['server']);
        $this->assertEqualsCanonicalizing(["environment-{$production->id}", "server-{$app->id}", "server-{$storage->id}"], $impact['nodes']);
        $this->assertSame([['label' => 'Alpha · Production', 'path' => 'app-01 → NFS → opslag-01']], array_map(
            fn (array $item): array => ['label' => $item['label'], 'path' => $item['path']],
            $impact['items'],
        ));
    }

    public function test_the_filters_live_in_the_address(): void
    {
        $this->signIn();
        ['project' => $project] = $this->inventory();

        Livewire::withQueryParams(['production' => '1', 'project' => (string) $project->id])
            ->test(Map::class)
            ->assertSet('productionOnly', true)
            ->assertSet('projectId', $project->id)
            ->assertSee('app-01');
    }

    public function test_a_chain_of_dependencies_keeps_each_server_next_to_what_needs_it(): void
    {
        ['storage' => $storage] = $this->inventory();
        $backup = Server::factory()->create(['name' => 'aaa-backup']);
        // Modelled bottom-up: the far end of the chain first.
        ServerDependency::query()->delete();
        ServerDependency::create(['server_id' => $storage->id, 'depends_on_id' => $backup->id, 'kind' => 'rsync']);
        ServerDependency::create(['server_id' => Server::query()->where('name', 'app-01')->value('id'), 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        Server::factory()->create(['name' => 'aaa-los']);

        $servers = collect(app(InfrastructureMap::class)->build()['columns']['servers'])->pluck('label')->all();

        $this->assertSame(['app-01', 'opslag-01', 'aaa-backup', 'test-01', 'aaa-los'], $servers);
    }

    public function test_the_client_filter_and_what_hangs_nowhere(): void
    {
        ['project' => $project] = $this->inventory();
        $client = Client::factory()->create();
        $project->update(['client_id' => $client->id]);
        $other = Project::factory()->create(['name' => 'Ander']);
        Environment::factory()->for($other)->create();
        Server::factory()->create(['name' => 'legacy-01', 'status' => ServerStatus::Decommissioned]);
        Hostname::factory()->for(Domain::factory()->create(['name' => 'los.test']))->create(['fqdn' => 'www.los.test']);

        $all = app(InfrastructureMap::class)->build();
        $mine = app(InfrastructureMap::class)->build(clientId: $client->id);

        $this->assertSame('www.los.test', collect($all['columns']['hostnames'])->last()['label']);
        $this->assertSame(['gray', 'Uit gebruik'], [collect($all['columns']['servers'])->firstWhere('label', 'legacy-01')['color'], collect($all['columns']['servers'])->firstWhere('label', 'legacy-01')['status']]);
        $this->assertSame(['Alpha'], collect($mine['groups'])->pluck('label')->all());
        $this->assertNotContains('www.los.test', collect($mine['columns']['hostnames'])->pluck('label'));
        $this->assertNotContains('los.test', collect($mine['columns']['domains'])->pluck('label'));
        $this->assertNotContains('legacy-01', collect($mine['columns']['servers'])->pluck('label'));
    }

    public function test_choosing_another_client_drops_a_project_that_is_not_theirs(): void
    {
        $this->signIn();
        ['project' => $project] = $this->inventory();
        $client = Client::factory()->create();

        Livewire::test(Map::class)
            ->set('projectId', $project->id)
            ->set('clientId', $client->id)
            ->assertSet('projectId', null);
    }

    public function test_impact_of_a_server_that_is_gone_or_of_an_archived_project(): void
    {
        $this->signIn();
        ['storage' => $storage, 'project' => $project] = $this->inventory();
        $map = Livewire::test(Map::class)->instance();

        $this->assertTrue($map->impact(999999)['missing']);

        $project->update(['status' => ProjectStatus::Archived]);
        $this->assertSame([], $map->impact($storage->id)['items']);
    }

    public function test_each_node_shows_its_own_state(): void
    {
        ['app' => $app, 'storage' => $storage, 'hostname' => $hostname] = $this->inventory();
        $ping = Check::factory()->forServer($storage)->create();
        $ping->forceFill(['state' => CheckState::Down, 'last_checked_at' => now(), 'last_state_changed_at' => now()])->saveQuietly();
        $http = Check::factory()->for($hostname)->create();
        $http->forceFill(['state' => CheckState::Down, 'last_checked_at' => now(), 'last_state_changed_at' => now()])->saveQuietly();
        $hostname->domain->update(['expires_on' => now()->subDay()->toDateString(), 'auto_renews' => false]);

        $map = app(InfrastructureMap::class)->build();
        $node = fn (string $column, string $label): array => collect($map['columns'][$column])->firstWhere('label', $label);

        $this->assertSame('danger', $node('servers', 'opslag-01')['color'], 'Its own ping is down.');
        $this->assertSame('gray', $node('servers', 'app-01')['color'], 'A site down on it is not the server down.');
        $this->assertSame('danger', $node('hostnames', 'app.alpha.test')['color']);
        $this->assertSame(['danger', 'Verlopen'], [$node('domains', 'alpha.test')['color'], $node('domains', 'alpha.test')['status']]);
    }

    public function test_a_server_without_a_public_address_shows_its_private_one(): void
    {
        Server::factory()->create(['name' => 'office-01', 'public_ipv4' => null, 'private_ip' => '192.168.60.10']);

        $map = app(InfrastructureMap::class)->build();

        $this->assertSame('192.168.60.10', collect($map['columns']['servers'])->firstWhere('label', 'office-01')['sub']);
    }

    public function test_a_server_without_environments_that_needs_the_clicked_one_goes_down_with_it(): void
    {
        $this->signIn();
        ['storage' => $storage] = $this->inventory();
        $backup = Server::factory()->create(['name' => 'backup-01']);
        ServerDependency::create(['server_id' => $backup->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS-mount voor de nachtelijke backup']);

        $impact = Livewire::test(Map::class)->instance()->impact($storage->id);
        $edge = collect(app(InfrastructureMap::class)->build()['edges'])->firstWhere('to', "server-{$backup->id}");

        $this->assertContains("server-{$backup->id}", $impact['nodes']);
        $this->assertSame(2, $impact['servers']);
        $this->assertSame(['NFS-mount vo...', 'NFS-mount voor de nachtelijke backup'], [$edge['label'], $edge['title']]);
    }

    public function test_a_server_on_a_local_network_hangs_from_its_network(): void
    {
        ['app' => $app, 'project' => $project] = $this->inventory();
        $office = Network::factory()->create(['name' => 'Main office', 'subnet' => '192.168.60.0/24']);
        $rack = Network::factory()->create(['name' => 'Rack', 'subnet' => null, 'location' => 'Amsterdam']);
        $local = Server::factory()->create(['name' => 'office-01', 'public_ipv4' => null, 'private_ip' => '192.168.60.10', 'network_id' => $office->id]);
        $app->update(['network_id' => $rack->id]);
        Network::factory()->create(['name' => 'Leeg']);

        $map = app(InfrastructureMap::class)->build();
        $first = collect($map['columns']['providers']);
        $edges = collect($map['edges'])->map(fn (array $edge): string => "{$edge['from']} > {$edge['to']} ({$edge['kind']})");

        // Sorted with the providers by the place of their servers; a network
        // without servers stays off.
        $this->assertSame(['Rack', 'Hoster', 'Main office'], $first->pluck('label')->all());
        $this->assertSame(['192.168.60.0/24', true], [$first->firstWhere('label', 'Main office')['sub'], $first->firstWhere('label', 'Main office')['subMono']]);
        $this->assertSame(['Amsterdam', false], [$first->firstWhere('label', 'Rack')['sub'], $first->firstWhere('label', 'Rack')['subMono']]);
        $this->assertContains("network-{$office->id} > server-{$local->id} (network)", $edges);
        $this->assertContains("network-{$rack->id} > server-{$app->id} (network)", $edges);
        $this->assertContains("provider-{$app->provider_id} > server-{$app->id} (hosts)", $edges);

        // Alpha does not run in the office.
        $filtered = app(InfrastructureMap::class)->build(projectId: $project->id);
        $this->assertSame(['Rack', 'Hoster'], collect($filtered['columns']['providers'])->pluck('label')->all());
    }

    public function test_names_are_escaped_on_the_map(): void
    {
        $this->signIn();
        $this->inventory();
        Server::factory()->create(['name' => '<script>alert("x")</script>']);

        $this->get(Map::getUrl())
            ->assertSuccessful()
            ->assertDontSee('<script>alert("x")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    /**
     * Alpha: production on app-01, which needs opslag-01 (NFS); staging on
     * test-01; one hostname on production.
     *
     * @return array<string, mixed>
     */
    private function inventory(): array
    {
        $provider = Provider::factory()->create(['name' => 'Hoster']);
        $project = Project::factory()->create(['name' => 'Alpha']);
        $production = Environment::factory()->for($project)->create();
        $staging = Environment::factory()->for($project)->staging()->create();
        $app = Server::factory()->create(['name' => 'app-01', 'provider_id' => $provider->id]);
        $storage = Server::factory()->create(['name' => 'opslag-01', 'provider_id' => $provider->id]);
        $test = Server::factory()->create(['name' => 'test-01']);
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        EnvironmentServer::create(['environment_id' => $production->id, 'server_id' => $app->id, 'role' => ServerRole::App]);
        EnvironmentServer::create(['environment_id' => $staging->id, 'server_id' => $test->id, 'role' => ServerRole::App]);
        $hostname = Hostname::factory()->for(Domain::factory()->create(['name' => 'alpha.test']))->create(['fqdn' => 'app.alpha.test', 'environment_id' => $production->id]);

        return compact('provider', 'project', 'production', 'staging', 'app', 'storage', 'test', 'hostname');
    }
}
