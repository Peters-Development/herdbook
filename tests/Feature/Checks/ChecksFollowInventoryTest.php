<?php

namespace Tests\Feature\Checks;

use App\Enums\ProjectStatus;
use App\Enums\ServerStatus;
use App\Models\Check;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Server;
use App\Services\Checks\DefaultChecks;
use App\Services\Checks\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

/**
 * Checks follow what they check: renames, new addresses, retired servers
 * and archived projects.
 */
class ChecksFollowInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, new FakeProbe);
    }

    public function test_renaming_a_hostname_moves_its_checks_along(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'app.example.net', 'domain_id' => Domain::factory()->create(['name' => 'example.net'])->id]);
        app(DefaultChecks::class)->createFor($hostname);

        $hostname->update(['fqdn' => 'portal.example.net']);

        $this->assertSame(
            ['https://portal.example.net/', 'portal.example.net:443', 'portal.example.net'],
            $hostname->checks()->orderBy('id')->pluck('target')->all(),
        );
        $this->assertStringContainsString('portal.example.net', $hostname->checks()->first()->name);
    }

    public function test_retiring_a_server_or_archiving_a_project_pauses_their_checks(): void
    {
        $server = Server::factory()->create();
        $serverCheck = Check::factory()->forServer($server)->create();
        $environment = Environment::factory()->create();
        $hostnameCheck = Check::factory()->create(['hostname_id' => Hostname::factory()->create(['environment_id' => $environment->id])->id]);

        $server->update(['status' => ServerStatus::Decommissioned]);
        $environment->project->update(['status' => ProjectStatus::Archived]);

        $this->assertFalse($serverCheck->fresh()->enabled);
        $this->assertFalse($hostnameCheck->fresh()->enabled);
    }

    public function test_a_rename_only_touches_checks_about_that_exact_name(): void
    {
        $domain = Domain::factory()->create(['name' => 'example.net']);
        $apex = Hostname::factory()->create(['fqdn' => 'example.net', 'domain_id' => $domain->id]);
        $own = Check::factory()->create(['hostname_id' => $apex->id, 'target' => 'https://example.net/', 'name' => 'HTTP example.net']);
        $api = Check::factory()->create(['hostname_id' => $apex->id, 'target' => 'https://api.example.net/health', 'name' => 'API']);

        $apex->update(['fqdn' => 'www.example.net']);

        $this->assertSame('https://www.example.net/', $own->fresh()->target);
        $this->assertSame('https://api.example.net/health', $api->fresh()->target);
    }

    public function test_renaming_to_a_wildcard_pauses_the_checks(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'app.example.net', 'domain_id' => Domain::factory()->create(['name' => 'example.net'])->id]);
        $check = Check::factory()->create(['hostname_id' => $hostname->id, 'target' => 'https://app.example.net/']);

        $hostname->update(['fqdn' => '*.example.net']);

        $this->assertFalse($check->fresh()->enabled);
        $this->assertSame('https://app.example.net/', $check->fresh()->target);
    }

    public function test_a_new_server_address_moves_its_checks(): void
    {
        $server = Server::factory()->create(['public_ipv4' => '203.0.113.10']);
        $ping = Check::factory()->forServer($server)->create(['target' => '203.0.113.10']);

        $server->update(['public_ipv4' => '203.0.113.20']);

        $this->assertSame('203.0.113.20', $ping->fresh()->target);
    }
}
