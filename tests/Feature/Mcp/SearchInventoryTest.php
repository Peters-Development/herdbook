<?php

namespace Tests\Feature\Mcp;

use App\Enums\MaintenanceTask;
use App\Enums\ServerRole;
use App\Mcp\Tools\SearchInventory;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Maintenance;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class SearchInventoryTest extends TestCase
{
    use ActsAsAgent, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['herdbook.mcp.enabled' => true]);
        [, $this->token] = $this->agentToken();
    }

    public function test_a_project_shows_its_environments_and_their_servers_and_nothing_else(): void
    {
        $project = Project::factory()->for(Client::factory()->state(['name' => 'Acme', 'contact_email' => 'ops@acme.example']))->create([
            'name' => 'Shop', 'slug' => 'shop', 'notes' => 'project-note', 'repository_url' => 'https://github.com/acme/shop',
        ]);
        $production = Environment::factory()->for($project)->create(['notes' => 'environment-note']);
        $web = Server::factory()->create(['name' => 'web-1']);
        $app = Server::factory()->create(['name' => 'app-1']);
        $production->servers()->attach($web, ['role' => ServerRole::Proxy, 'notes' => 'pivot-note']);
        // One VPS, two roles: one entry.
        $production->servers()->attach($app, ['role' => ServerRole::App]);
        $production->servers()->attach($app, ['role' => ServerRole::Database]);

        $response = $this->search(['query' => 'shop', 'type' => 'projects']);

        $found = $response->json('result.structuredContent.projects.0');
        $this->assertSame(['id', 'name', 'slug', 'client', 'status', 'environments', 'url'], array_keys($found));
        $this->assertSame(['id' => $project->id, 'name' => 'Shop', 'slug' => 'shop', 'client' => 'Acme', 'status' => 'active'], array_slice($found, 0, 5));
        $this->assertSame([[
            'name' => 'Production',
            'kind' => 'production',
            'servers' => [
                ['id' => $app->id, 'name' => 'app-1', 'roles' => ['app', 'database']],
                ['id' => $web->id, 'name' => 'web-1', 'roles' => ['proxy']],
            ],
        ]], $found['environments']);
        $this->assertStringEndsWith("/projects/{$project->id}", $found['url']);
        foreach (['project-note', 'environment-note', 'pivot-note', 'ops@acme.example', 'github.com'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $response->getContent());
        }
    }

    public function test_a_project_without_environments_has_an_empty_list(): void
    {
        Project::factory()->create(['name' => 'Idea']);

        $this->assertSame([], $this->search(['query' => 'Idea', 'type' => 'projects'])->json('result.structuredContent.projects.0.environments'));
    }

    public function test_a_server_shows_where_it_runs_and_when_work_was_last_done_but_no_address(): void
    {
        $server = Server::factory()->for(Provider::factory()->state(['name' => 'Hetzner']))->create([
            'name' => 'app-1', 'public_ipv4' => '203.0.113.7', 'private_ip' => '10.0.0.5', 'tailscale_ip' => '100.64.0.9',
            'provider_hostname' => 'static.7.113.0.203.clients.example', 'ssh_user' => 'deploy-user', 'notes' => 'server-note',
        ]);
        $production = Environment::factory()->for(Project::factory()->state(['name' => 'Shop']))->create();
        $staging = Environment::factory()->staging()->for($production->project)->create();
        $production->servers()->attach($server, ['role' => ServerRole::Database]);
        $production->servers()->attach($server, ['role' => ServerRole::App]);
        $staging->servers()->attach($server, ['role' => ServerRole::App]);
        Maintenance::factory()->create(['tasks' => [MaintenanceTask::Updates->value], 'starts_at' => '2026-11-01 10:00:00'])->servers()->attach($server);

        $response = $this->search(['query' => 'app-1', 'type' => 'servers']);

        $found = $response->json('result.structuredContent.servers.0');
        $this->assertSame(['id', 'name', 'status', 'os', 'provider', 'environments', 'last_done', 'url'], array_keys($found));
        $this->assertSame(['id' => $server->id, 'name' => 'app-1', 'status' => 'active', 'os' => 'Ubuntu 24.04', 'provider' => 'Hetzner'], array_slice($found, 0, 5));
        $shop = ['id' => $production->project_id, 'name' => 'Shop'];
        $this->assertSame([
            ['project' => $shop, 'name' => 'Production', 'kind' => 'production', 'roles' => ['app', 'database']],
            ['project' => $shop, 'name' => 'Staging', 'kind' => 'staging', 'roles' => ['app']],
        ], $found['environments']);
        $this->assertSame(['updates' => '2026-11-01T11:00:00+01:00', 'reboot' => null, 'inspection' => null, 'upgrade' => null], $found['last_done']);
        foreach (['203.0.113', '10.0.0.5', '100.64.0.9', 'static.7', 'deploy-user', 'server-note'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $response->getContent());
        }
    }

    public function test_a_server_is_found_by_its_address_without_any_address_coming_back(): void
    {
        // An address is stored as it was typed.
        $server = Server::factory()->create([
            'name' => 'db-1', 'public_ipv4' => '203.0.113.7', 'public_ipv6' => '2001:DB8:0:0::7', 'private_ip' => '10.0.0.5', 'tailscale_ip' => '100.64.0.9',
        ]);
        Server::factory()->create(['name' => 'other', 'public_ipv4' => '203.0.113.70']);

        foreach (['203.0.113.7', '2001:db8::7', '2001:0db8:0:0:0:0:0:7', '10.0.0.5', '100.64.0.9'] as $address) {
            $response = $this->search(['query' => $address, 'type' => 'servers']);

            $this->assertSame([$server->id], array_column($response->json('result.structuredContent.servers'), 'id'), $address);
            foreach (['203.0.113', '2001:', '2001:', '10.0.0.5', '100.64.0.9'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $response->getContent(), $address);
            }
        }

        $this->assertSame([], $this->ids('servers', '203.0.113'));
    }

    public function test_a_server_is_found_by_its_whole_provider_hostname_or_its_first_label_only(): void
    {
        $server = Server::factory()->create(['name' => 'app-1', 'provider_hostname' => 'srv1234.provider.example']);
        Server::factory()->create(['name' => 'web-1', 'provider_hostname' => 'ec2-203-0-113-7.compute-1.example']);
        Server::factory()->create(['name' => 'db-1', 'provider_hostname' => 'static.7.113.0.203.clients.example']);
        Server::factory()->create(['name' => 'mail-1', 'provider_hostname' => '7.113.0.203.reverse.example']);

        $this->assertSame([$server->id], $this->ids('servers', 'srv1234'));
        $this->assertSame([$server->id], $this->ids('servers', 'srv1234.provider.example'));
        // Part of it would spell out the address it often holds: no part of a
        // label, no several labels, no number.
        foreach (['srv12', 'ec2-203-0', 'srv1234.provider', 'static.7', 'static.7.113', '7'] as $part) {
            $this->assertSame([], $this->ids('servers', $part), $part);
        }
    }

    public function test_the_first_label_of_hostname_f_finds_the_server_but_a_git_remote_does_not(): void
    {
        $server = Server::factory()->create(['name' => 'app-1']);
        Server::factory()->create(['name' => 'app-10']);
        Server::factory()->create(['name' => 'github']);

        $this->assertSame([$server->id], $this->ids('servers', 'app-1.example.com'));
        $this->assertSame([], $this->ids('servers', 'github.com/acme/shop'));
    }

    public function test_a_project_is_found_by_its_git_remote_in_any_form_but_never_by_part_of_it(): void
    {
        $project = Project::factory()->create(['slug' => 'storefront', 'repository_url' => 'https://deploy:hunter2@github.com/acme/shop.git']);
        Project::factory()->create(['slug' => 'admin', 'repository_url' => 'https://github.com/acme/shop-admin']);

        foreach ([
            'git@github.com:acme/shop.git',
            'git@github.com:acme/Shop.GIT',
            'https://github.com/acme/shop',
            'https://github.com/Acme/Shop/',
            'ssh://git@github.com:22/acme/shop.git',
            'github.com/acme/shop',
        ] as $remote) {
            $this->assertSame([$project->id], $this->ids('projects', $remote), $remote);
        }

        // A LIKE on the URL could be made to spell out its credentials.
        foreach (['hunter2', 'deploy:hunter2', 'github.com/acme/sh'] as $part) {
            $this->assertSame([], $this->ids('projects', $part), $part);
        }
    }

    public function test_a_project_without_a_repository_is_found_by_the_name_of_the_remote(): void
    {
        $project = Project::factory()->create(['slug' => 'shop', 'repository_url' => null]);
        Project::factory()->create(['slug' => 'shop-admin', 'repository_url' => null]);
        Project::factory()->create(['slug' => 'other', 'repository_url' => 'https://github.com/elsewhere/shop']);

        $this->assertSame([$project->id], $this->ids('projects', 'git@github.com:acme/shop.git'));
    }

    public function test_the_remote_of_a_repository_in_its_forms(): void
    {
        $this->assertSame('github.com/acme/shop', SearchInventory::remote('git@github.com:acme/shop.git'));
        $this->assertSame('gitlab.example.com/team/sub/app', SearchInventory::remote('https://gitlab.example.com/team/sub/app.git'));
        $this->assertNull(SearchInventory::remote('shop'));
        $this->assertNull(SearchInventory::remote('app-1.example.com'));
        $this->assertNull(SearchInventory::remote('https://github.com/'));
    }

    public function test_a_hostname_shows_its_project_environment_and_servers(): void
    {
        $environment = Environment::factory()->for(Project::factory()->state(['name' => 'Shop']))->create();
        $server = Server::factory()->create(['name' => 'app-1']);
        $environment->servers()->attach($server, ['role' => ServerRole::App]);
        $hostname = Hostname::factory()->for(Domain::factory()->state(['name' => 'example.test']))->create(['fqdn' => 'shop.example.test', 'environment_id' => $environment->id, 'notes' => 'hostname-note']);
        Hostname::factory()->for(Domain::factory()->state(['name' => 'other.test']))->create(['fqdn' => 'mail.other.test']);

        $response = $this->search(['query' => 'shop.example.test', 'type' => 'hostnames']);

        $found = $response->json('result.structuredContent.hostnames');
        $this->assertSame([[
            'id' => $hostname->id,
            'fqdn' => 'shop.example.test',
            'project' => ['id' => $environment->project_id, 'name' => 'Shop'],
            'environment' => ['name' => 'Production', 'kind' => 'production'],
            'servers' => [['id' => $server->id, 'name' => 'app-1', 'roles' => ['app']]],
            'url' => $found[0]['url'],
        ]], $found);
        $this->assertStringNotContainsString('hostname-note', $response->getContent());
    }

    public function test_a_hostname_without_an_environment(): void
    {
        Hostname::factory()->for(Domain::factory()->state(['name' => 'example.test']))->create(['fqdn' => 'parked.example.test']);

        $found = $this->search(['query' => 'parked', 'type' => 'hostnames'])->json('result.structuredContent.hostnames.0');

        $this->assertSame([null, null, []], [$found['project'], $found['environment'], $found['servers']]);
    }

    public function test_without_a_query_it_lists_everything_with_its_links_loaded_up_front(): void
    {
        // Strict mode turns a lazy load into an error once a query returns
        // more than one model: so two of everything, fully linked.
        $this->linkedInventory(2);

        $everything = $this->search()->json('result.structuredContent');

        $this->assertSame(['projects', 'servers', 'hostnames', 'more'], array_keys($everything));
        $this->assertSame([2, 2, 2, []], [count($everything['projects']), count($everything['servers']), count($everything['hostnames']), $everything['more']]);
        $this->assertSame(['servers', 'more'], array_keys($this->search(['type' => 'servers'])->json('result.structuredContent')));
    }

    public function test_the_number_of_queries_does_not_grow_with_what_is_found(): void
    {
        // "Last done" is a query of its own when it isn't loaded with the list:
        // strict mode doesn't see that one.
        $this->linkedInventory(2);
        $this->search(); // The first call of a minute also marks the agent as used.
        $few = $this->queriesFor(fn () => $this->search());

        $this->linkedInventory(2);
        $this->assertSame($few, $this->queriesFor(fn () => $this->search()));
    }

    public function test_it_says_when_there_are_more_than_it_shows(): void
    {
        Server::factory()->count(25)->create();
        $this->assertSame([], $this->search(['type' => 'servers'])->json('result.structuredContent.more'));

        Server::factory()->create();
        $found = $this->search(['type' => 'servers'])->json('result.structuredContent');

        $this->assertCount(25, $found['servers']);
        $this->assertSame(['servers'], $found['more']);
    }

    public function test_percent_underscore_and_the_escape_character_are_taken_literally(): void
    {
        $underscore = Project::factory()->create(['name' => 'a_b', 'slug' => 'a-b']);
        Project::factory()->create(['name' => 'axb', 'slug' => 'axb']);
        $exclamation = Project::factory()->create(['name' => 'Yahoo!', 'slug' => 'yahoo']);

        $this->assertSame([$underscore->id], $this->ids('projects', 'a_b'));
        $this->assertSame([$exclamation->id], $this->ids('projects', 'Yahoo!'));
        $this->assertSame([], $this->ids('projects', '%'));
    }

    private function linkedInventory(int $count): void
    {
        foreach (range(1, $count) as $index) {
            $environment = Environment::factory()->for(Project::factory()->for(Client::factory()))->create();
            $environment->servers()->attach(Server::factory()->for(Provider::factory())->create(), ['role' => ServerRole::App]);
            Hostname::factory()->create(['environment_id' => $environment->id]);
        }
    }

    private function queriesFor(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    public function test_blank_arguments_count_as_left_out(): void
    {
        Project::factory()->create();

        foreach ([['type' => ''], ['type' => ' '], ['query' => '  '], ['query' => null]] as $arguments) {
            $this->assertCount(1, $this->search($arguments)->json('result.structuredContent.projects'), json_encode($arguments));
        }
    }

    public function test_a_wrong_type_is_refused_in_words_the_agent_can_act_on(): void
    {
        $this->assertSame('De geselecteerde type is ongeldig.', $this->callToolError($this->token, 'search_inventory', ['type' => 'domains']));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function search(array $arguments = []): TestResponse
    {
        $response = $this->callTool($this->token, 'search_inventory', $arguments)->assertOk();
        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return $response;
    }

    /**
     * @return list<int>
     */
    private function ids(string $type, string $query): array
    {
        return array_column($this->search(['query' => $query, 'type' => $type])->json("result.structuredContent.{$type}"), 'id');
    }
}
