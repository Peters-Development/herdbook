<?php

namespace Tests\Feature\Inventory;

use App\Enums\ServerRole;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Services\Impact\ImpactAnalyzer;
use App\Services\Impact\ImpactedEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpactAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_storage_server_takes_down_what_runs_on_the_app_server_that_mounts_it(): void
    {
        $storage = Server::factory()->create(['name' => 'storage']);
        $app = Server::factory()->create(['name' => 'app']);
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        $production = Environment::factory()->create();
        $this->runsOn($production, $app, ServerRole::App);
        $this->runsOn($production, $storage, ServerRole::Storage);

        $impacts = app(ImpactAnalyzer::class)->forServer($storage);

        $this->assertCount(1, $impacts);
        $this->assertTrue($impacts->first()->environment->is($production));
        // the direct route (storage itself) wins over the one through the app server
        $this->assertTrue($impacts->first()->isDirect());
    }

    public function test_an_indirect_dependency_shows_the_path(): void
    {
        $storage = Server::factory()->create(['name' => 'storage']);
        $app = Server::factory()->create(['name' => 'app']);
        $web = Server::factory()->create(['name' => 'web']);
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        ServerDependency::create(['server_id' => $web->id, 'depends_on_id' => $app->id, 'kind' => 'WireGuard']);
        $this->runsOn($environment = Environment::factory()->create(), $web, ServerRole::Proxy);

        $impact = app(ImpactAnalyzer::class)->forServer($storage)->sole();

        $this->assertTrue($impact->environment->is($environment));
        $this->assertSame('web → WireGuard → app → NFS → storage', $impact->path());
    }

    public function test_servers_that_go_down_together_take_down_each_environment_once_through_the_shortest_chain(): void
    {
        $local = Server::factory()->create(['name' => 'office-01']);
        $nas = Server::factory()->create(['name' => 'nas']);
        $backup = Server::factory()->create(['name' => 'backup']);
        ServerDependency::create(['server_id' => $backup->id, 'depends_on_id' => $nas->id, 'kind' => 'NFS']);
        $this->runsOn($office = Environment::factory()->create(), $local, ServerRole::App);
        $this->runsOn($office, $nas, ServerRole::Storage);
        $this->runsOn($elsewhere = Environment::factory()->staging()->create(), $backup, ServerRole::App);

        $impacts = app(ImpactAnalyzer::class)->forServers([$local->id, $nas->id])->keyBy(fn (ImpactedEnvironment $impact): int => $impact->environment->id);

        $this->assertCount(2, $impacts);
        $this->assertTrue($impacts[$office->id]->isDirect());
        $this->assertSame('backup → NFS → nas', $impacts[$elsewhere->id]->path());
        $this->assertCount(0, app(ImpactAnalyzer::class)->forServers([]));
    }

    public function test_a_dependency_cycle_ends(): void
    {
        $a = Server::factory()->create();
        $b = Server::factory()->create();
        ServerDependency::create(['server_id' => $a->id, 'depends_on_id' => $b->id, 'kind' => 'NFS']);
        ServerDependency::create(['server_id' => $b->id, 'depends_on_id' => $a->id, 'kind' => 'NFS']);
        $this->runsOn(Environment::factory()->create(), $a, ServerRole::App);
        $this->runsOn(Environment::factory()->staging()->create(), $b, ServerRole::App);

        $this->assertCount(2, app(ImpactAnalyzer::class)->forServer($a));
    }

    public function test_a_server_nothing_runs_on_affects_nothing(): void
    {
        $this->assertCount(0, app(ImpactAnalyzer::class)->forServer(Server::factory()->create()));
    }

    public function test_unrelated_servers_are_left_out(): void
    {
        $failing = Server::factory()->create();
        $this->runsOn(Environment::factory()->create(), Server::factory()->create(), ServerRole::App);

        $this->assertCount(0, app(ImpactAnalyzer::class)->forServer($failing));
    }

    public function test_impacts_carry_the_role(): void
    {
        $server = Server::factory()->create();
        $this->runsOn(Environment::factory()->create(), $server, ServerRole::Database);

        $impact = app(ImpactAnalyzer::class)->forServer($server)->sole();

        $this->assertInstanceOf(ImpactedEnvironment::class, $impact);
        $this->assertSame(ServerRole::Database, $impact->role);
    }

    public function test_the_mail_server_of_a_domain_takes_down_the_environments_of_its_hostnames(): void
    {
        $mail = Server::factory()->create(['name' => 'mail-1']);
        $vps = Server::factory()->create(['name' => 'vps-1']);
        $production = Environment::factory()->create();
        $this->runsOn($production, $vps, ServerRole::App);
        $domain = Domain::factory()->create(['name' => 'example.com', 'mail_server_id' => $mail->id]);
        Hostname::factory()->for($domain)->create(['fqdn' => 'www.example.com', 'environment_id' => $production->id]);
        Hostname::factory()->for($domain)->create(['fqdn' => 'mail.example.com', 'environment_id' => null]);

        $impact = app(ImpactAnalyzer::class)->forServer($mail)->sole();

        $this->assertTrue($impact->environment->is($production));
        $this->assertTrue($impact->domain->is($domain));
        $this->assertSame(ServerRole::Mail, $impact->role);
        $this->assertTrue($impact->isDirect());
        $this->assertSame('example.com → mail-1', $impact->path());
        $this->assertNull(app(ImpactAnalyzer::class)->forServer($vps)->sole()->domain);
    }

    public function test_mail_on_the_failing_server_itself_wins_over_running_on_a_server_that_needs_it(): void
    {
        $storage = Server::factory()->create(['name' => 'storage']);
        $app = Server::factory()->create(['name' => 'app']);
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        $production = Environment::factory()->create();
        $this->runsOn($production, $app, ServerRole::App);
        Hostname::factory()->for(Domain::factory()->create(['name' => 'example.com', 'mail_server_id' => $storage->id]))->create(['environment_id' => $production->id]);

        $impact = app(ImpactAnalyzer::class)->forServer($storage)->sole();

        $this->assertTrue($impact->isDirect());
        $this->assertSame('example.com → storage', $impact->path());
    }

    public function test_running_on_the_server_wins_over_mail_through_a_domain_and_a_chain_starts_at_the_domain(): void
    {
        $mail = Server::factory()->create(['name' => 'mail-1']);
        $storage = Server::factory()->create(['name' => 'storage']);
        ServerDependency::create(['server_id' => $mail->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        $domain = Domain::factory()->create(['name' => 'example.com', 'mail_server_id' => $mail->id]);
        $both = Environment::factory()->create();
        $this->runsOn($both, $mail, ServerRole::Other);
        Hostname::factory()->for($domain)->create(['environment_id' => $both->id]);
        $onlyMail = Environment::factory()->staging()->create();
        Hostname::factory()->for($domain)->create(['environment_id' => $onlyMail->id]);

        $impacts = app(ImpactAnalyzer::class)->forServer($mail)->keyBy(fn (ImpactedEnvironment $impact): int => $impact->environment->id);

        $this->assertNull($impacts[$both->id]->domain);
        $this->assertSame(ServerRole::Other, $impacts[$both->id]->role);
        $this->assertTrue($impacts[$onlyMail->id]->domain->is($domain));
        $this->assertSame('example.com → mail-1 → NFS → storage', app(ImpactAnalyzer::class)->forServer($storage)->firstWhere('environment.id', $onlyMail->id)->path());
    }

    private function runsOn(Environment $environment, Server $server, ServerRole $role): void
    {
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => $role]);
    }
}
