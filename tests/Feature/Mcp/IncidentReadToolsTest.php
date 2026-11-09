<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgentAccess;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServerRole;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Mcp\Serializer;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Incident;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class IncidentReadToolsTest extends TestCase
{
    use ActsAsAgent, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-09 12:00:00');
        config(['herdbook.mcp.enabled' => true]);
        [, $this->token] = $this->agentToken();
    }

    public function test_an_item_has_exactly_these_fields_and_nothing_of_the_inventory_it_doesnt_name(): void
    {
        $server = Server::factory()->create(['name' => 'app-1', 'public_ipv4' => '192.0.2.10', 'notes' => 'secret-notes']);
        $project = Project::factory()->create(['name' => 'Shop']);
        $incident = Incident::factory()->for(User::factory()->state(['name' => 'Ada']))->create([
            'title' => 'Uploads failed',
            'severity' => IncidentSeverity::Sev1,
            'status' => IncidentStatus::Monitoring,
            'occurred_on' => '2026-11-08',
            'started_at' => '2026-11-08 21:00:00',
            'detected_at' => '2026-11-08 21:05:00',
            'resolved_at' => null,
            'body' => 'the-report',
        ]);
        $incident->forceFill(['recorded_via' => 'deploy-agent'])->save();
        $incident->servers()->attach($server);
        $incident->projects()->attach($project);
        $incident->domains()->attach(Domain::factory()->create(['name' => 'example.com']));
        $incident->networks()->attach(Network::factory()->create(['name' => 'Office']));
        $incident->clients()->attach(Client::factory()->create(['name' => 'Acme']));
        $incident->actions()->createMany([['description' => 'Alert on NFS latency'], ['description' => 'Done already', 'done_at' => now()]]);

        $found = $this->callToolSuccessfully($this->token, 'list_incidents');

        $this->assertSame([[
            'id' => $incident->id,
            'title' => 'Uploads failed',
            'severity' => 'sev1',
            'status' => 'monitoring',
            'source' => 'manual',
            'occurred_on' => '2026-11-08',
            'started_at' => '2026-11-08T22:00:00+01:00',
            'detected_at' => '2026-11-08T22:05:00+01:00',
            'resolved_at' => null,
            'projects' => [['id' => $project->id, 'name' => 'Shop']],
            'servers' => [['id' => $server->id, 'name' => 'app-1']],
            'domains' => [['id' => $incident->domains->sole()->id, 'name' => 'example.com']],
            'networks' => [['id' => $incident->networks->sole()->id, 'name' => 'Office']],
            'clients' => [['id' => $incident->clients->sole()->id, 'name' => 'Acme']],
            'open_actions' => 1,
            'created_by' => 'Ada',
            'via' => 'deploy-agent',
            'version' => $incident->fresh()->version(),
            'url' => IncidentResource::getUrl('view', ['record' => $incident]),
        ]], $found['incidents']);
        $this->assertFalse($found['more']);

        $answer = json_encode($found, JSON_THROW_ON_ERROR);
        foreach (['the-report', '192.0.2.10', 'secret-notes'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $answer);
        }
    }

    public function test_unresolved_by_default_open_first_then_by_severity(): void
    {
        $watched = Incident::factory()->create(['status' => IncidentStatus::Monitoring, 'severity' => IncidentSeverity::Sev1]);
        $minor = Incident::factory()->create(['status' => IncidentStatus::Open, 'severity' => IncidentSeverity::Sev3]);
        $critical = Incident::factory()->create(['status' => IncidentStatus::Open, 'severity' => IncidentSeverity::Sev1]);
        $resolved = Incident::factory()->resolved()->create();

        $this->assertSame([$critical->id, $minor->id, $watched->id], $this->ids());
        $this->assertSame([$resolved->id], $this->ids(['status' => 'resolved']));
        $this->assertSame([$minor->id], $this->ids(['severity' => 'sev3']));
        $this->assertCount(4, $this->ids(['status' => 'any']));
    }

    public function test_since_leaves_out_what_occurred_before_that_day(): void
    {
        Incident::factory()->create(['occurred_on' => '2026-11-01']);
        $recent = Incident::factory()->create(['occurred_on' => '2026-11-05']);

        $this->assertSame([$recent->id], $this->ids(['since' => '2026-11-05']));
        $this->assertStringContainsString('since', $this->callToolError($this->token, 'list_incidents', ['since' => 'last week']));
    }

    public function test_the_incidents_of_a_project_reach_as_far_as_herdbook_counts_them(): void
    {
        $production = Environment::factory()->create();
        $app = Server::factory()->create(['network_id' => ($network = Network::factory()->create())->id]);
        EnvironmentServer::create(['environment_id' => $production->id, 'server_id' => $app->id, 'role' => ServerRole::App]);

        $onProject = Incident::factory()->resolved()->create();
        $onProject->projects()->attach($production->project_id);
        $onServer = Incident::factory()->create();
        $onServer->servers()->attach($app);
        $onNetwork = Incident::factory()->create();
        $onNetwork->networks()->attach($network);
        Incident::factory()->create()->servers()->attach(Server::factory()->create());

        $this->assertEqualsCanonicalizing([$onProject->id, $onServer->id, $onNetwork->id], $this->ids(['status' => 'any', 'project_ids' => [$production->project_id]]));
        $this->assertSame([$onServer->id], $this->ids(['server_ids' => [$app->id]]));
    }

    public function test_get_incident_adds_its_texts_timeline_and_actions(): void
    {
        $incident = Incident::factory()->create(['impact' => 'Uploads failed for an hour.', 'root_cause' => 'NFS stalled.', 'body' => 'The report.', 'source' => IncidentSource::Check]);
        $incident->timelineEntries()->create(['occurred_at' => '2026-11-08 21:05:00', 'note' => 'Check HTTP shop.example.com went down: HTTP 503']);
        $incident->timelineEntries()->create(['occurred_at' => '2026-11-08 21:20:00', 'note' => 'Restarted php-fpm, no effect.', 'user_id' => User::factory()->create(['name' => 'Ada'])->id, 'recorded_via' => 'deploy-agent']);
        $action = $incident->actions()->create(['description' => 'Alert on NFS latency', 'due_on' => '2026-11-01']);

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertSame([...array_keys(Serializer::incident($incident->load(Serializer::INCIDENT_RELATIONS))), 'impact', 'root_cause', 'body', 'truncated', 'texts_changed', 'timeline', 'more_timeline', 'actions'], array_keys($found));
        $this->assertSame(['Uploads failed for an hour.', 'NFS stalled.', 'The report.', [], ['body' => null, 'root_cause' => null]], [$found['impact'], $found['root_cause'], $found['body'], $found['truncated'], $found['texts_changed']]);
        $this->assertSame([
            ['occurred_at' => '2026-11-08T22:05:00+01:00', 'note' => 'Check HTTP shop.example.com went down: HTTP 503', 'by' => null, 'via' => null],
            ['occurred_at' => '2026-11-08T22:20:00+01:00', 'note' => 'Restarted php-fpm, no effect.', 'by' => 'Ada', 'via' => 'deploy-agent'],
        ], $found['timeline']);
        $this->assertFalse($found['more_timeline']);
        $this->assertSame([['id' => $action->id, 'description' => 'Alert on NFS latency', 'due_on' => '2026-11-01', 'done_at' => null, 'overdue' => true]], $found['actions']);
    }

    public function test_a_long_timeline_shows_its_latest_entries_and_long_texts_are_cut(): void
    {
        $incident = Incident::factory()->create(['body' => str_repeat('a', Serializer::MAX_TEXT + 1)]);
        foreach (range(1, Serializer::TIMELINE_ENTRIES + 1) as $minute) {
            $incident->timelineEntries()->create(['occurred_at' => now()->subMinutes(200 - $minute), 'note' => "Entry {$minute} ".str_repeat('x', Serializer::MAX_NOTE)]);
        }

        $found = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertSame(['body'], $found['truncated']);
        $this->assertSame(Serializer::MAX_TEXT, mb_strlen($found['body']));
        $this->assertCount(Serializer::TIMELINE_ENTRIES, $found['timeline']);
        $this->assertTrue($found['more_timeline']);
        // The latest, in order: the first entry is the one left out.
        $this->assertStringStartsWith('Entry 2 ', $found['timeline'][0]['note']);
        $this->assertStringStartsWith('Entry '.(Serializer::TIMELINE_ENTRIES + 1).' ', $found['timeline'][Serializer::TIMELINE_ENTRIES - 1]['note']);
        $this->assertSame(Serializer::MAX_NOTE, mb_strlen($found['timeline'][0]['note']));
    }

    public function test_get_incident_says_so_when_there_is_none(): void
    {
        $this->assertSame('Er is geen incident met id 999. list_incidents vindt ze.', $this->callToolError($this->token, 'get_incident', ['id' => 999]));
    }

    public function test_listing_many_loads_what_it_shows_up_front(): void
    {
        // Strict mode turns a lazy load into an error once a query returns more than one model.
        foreach (range(1, 3) as $index) {
            $incident = Incident::factory()->for(User::factory())->create();
            $incident->servers()->attach(Server::factory()->create());
            $incident->actions()->create(['description' => 'Follow up']);
        }

        $this->assertCount(3, $this->ids());
    }

    public function test_every_token_may_read_incidents_and_the_tools_say_they_only_read(): void
    {
        foreach (AgentAccess::cases() as $access) {
            [, $token] = $this->agentToken(access: $access);
            $tools = collect($this->mcp($token, 'tools/list')->json('result.tools'))->keyBy('name');

            foreach (['list_incidents', 'get_incident'] as $name) {
                $this->assertSame(['readOnlyHint' => true, 'openWorldHint' => false], array_intersect_key($tools[$name]['annotations'], ['readOnlyHint' => 0, 'openWorldHint' => 0]), "{$name}, {$access->value}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<int>
     */
    private function ids(array $arguments = []): array
    {
        return array_column($this->callToolSuccessfully($this->token, 'list_incidents', $arguments)['incidents'], 'id');
    }
}
