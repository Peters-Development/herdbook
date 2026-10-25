<?php

namespace Tests\Feature\Inventory;

use App\Models\CredentialReference;
use App\Models\Document;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Network;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LinkCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_item_removes_its_links_but_not_what_it_linked_to(): void
    {
        $server = Server::factory()->create();
        $incident = Incident::factory()->create();
        $server->incidents()->attach($incident);
        $server->documents()->attach(Document::factory()->create());

        $server->delete();

        $this->assertSame(0, DB::table('incidentables')->count());
        $this->assertSame(0, DB::table('documentables')->count());
        $this->assertTrue($incident->exists());
    }

    public function test_deleting_a_network_removes_its_links(): void
    {
        $network = Network::factory()->create();
        $network->incidents()->attach(Incident::factory()->create());
        $network->credentialReferences()->attach(CredentialReference::factory()->create());

        $network->delete();

        $this->assertSame(0, DB::table('incidentables')->count());
        $this->assertSame(0, DB::table('credentialables')->count());
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_deleting_a_project_removes_its_environments_one_by_one_and_logs_it(): void
    {
        $environment = Environment::factory()->create();

        $environment->project->delete();

        $this->assertSame(0, Environment::count());
        $this->assertTrue(Activity::query()->where('subject_type', 'environment')->where('event', 'deleted')->exists());
    }

    public function test_deleting_a_domain_removes_its_hostnames_one_by_one_and_logs_it(): void
    {
        $hostname = Hostname::factory()->create();

        Domain::query()->find($hostname->domain_id)->delete();

        $this->assertSame(0, Hostname::count());
        $this->assertTrue(Activity::query()->where('subject_type', 'hostname')->where('event', 'deleted')->exists());
    }
}
