<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgentAccess;
use App\Enums\CheckState;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Filament\Resources\Incidents\Pages\EditIncident;
use App\Mcp\Serializer;
use App\Models\Check;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\Checks\CheckIncidents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class IncidentChangeToolsTest extends TestCase
{
    use ActsAsAgent, RefreshDatabase;

    private User $user;

    private string $token;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-09 12:00:00');
        config(['herdbook.mcp.enabled' => true]);
        $this->user = User::factory()->withAppAuthentication()->create(['name' => 'Ada']);
        [, $this->token] = $this->agentToken($this->user, AgentAccess::Write, name: 'deploy-agent');
        $this->server = Server::factory()->create(['name' => 'app-1', 'public_ipv4' => '192.0.2.10']);
    }

    public function test_an_update_changes_only_what_it_gives_and_a_list_replaces_the_links_of_its_kind(): void
    {
        $incident = $this->incident(['impact' => 'Slow uploads.', 'root_cause' => 'Unknown.']);
        $incident->domains()->attach($domain = Domain::factory()->create());
        $other = Server::factory()->create();

        $answer = $this->update($incident, ['title' => ' Storage  stall ', 'severity' => 'sev1', 'impact' => null, 'report' => 'NFS stalled.', 'server_ids' => [$other->id]]);

        $incident->refresh();
        $this->assertSame(
            ['Storage stall', IncidentSeverity::Sev1, null, 'Unknown.', 'NFS stalled.', [$other->id], [$domain->id]],
            [$incident->title, $incident->severity, $incident->impact, $incident->root_cause, $incident->body, $incident->servers()->pluck('servers.id')->all(), $incident->domains()->pluck('domains.id')->all()],
        );
        $this->assertSame($incident->version(), $answer['incident']['version']);
    }

    public function test_an_update_needs_the_version_it_read_and_monitoring_changes_it_too(): void
    {
        $incident = $this->incident();
        $read = $incident->version();

        // As monitoring does when its checks are up again.
        $incident->update(['status' => IncidentStatus::Monitoring]);

        $this->assertSame('Dit incident is gewijzigd sinds je het las (nu: monitoring). Lees het opnieuw met get_incident.', $this->callToolError($this->token, 'update_incident', ['id' => $incident->id, 'version' => $read, 'title' => 'x']));
        $this->assertStringContainsString('version', $this->callToolError($this->token, 'update_incident', ['id' => $incident->id, 'title' => 'x']));
        $this->assertSame('Uploads fail', $incident->refresh()->title);
    }

    public function test_an_update_checks_its_moments_against_what_it_keeps(): void
    {
        $incident = $this->incident(['started_at' => '2026-11-09 10:00:00', 'detected_at' => '2026-11-09 10:30:00']);
        $refused = fn (array $changes): string => $this->callToolError($this->token, 'update_incident', ['id' => $incident->id, 'version' => $incident->fresh()->version(), ...$changes]);

        $this->assertSame('detected_at: Het kan niet vóór het begin liggen.', $refused(['detected_at' => '2026-11-09T10:00']));
        // 12:00 in Amsterdam is after it was detected (10:30 UTC).
        $this->assertSame('started_at: Het kan niet na het ontdekken of oplossen liggen.', $refused(['started_at' => '2026-11-09T12:00']));
        $this->assertSame('resolved_at: Het kan niet in de toekomst liggen.', $refused(['resolved_at' => '2026-11-09T14:00']));

        $this->update($incident, ['started_at' => '2026-11-09T11:00', 'detected_at' => '2026-11-09T11:30']);
        $this->assertSame('2026-11-09 10:00:00', $incident->refresh()->started_at->toDateTimeString());
    }

    public function test_an_update_writes_no_masks_back_and_no_new_links_and_keeps_what_it_replaced(): void
    {
        $incident = $this->incident(['body' => 'ssh deploy@192.0.2.10 hung. See [the graph](https://elsewhere.example/g).']);
        $read = $this->callToolSuccessfully($this->token, 'get_incident', ['id' => $incident->id]);

        $this->assertStringStartsWith('report: Dit bevat een masker', $this->callToolError($this->token, 'update_incident', ['id' => $incident->id, 'version' => $read['version'], 'report' => $read['report']."\n\nFixed."]));
        $this->assertStringStartsWith('report: Geen links naar andere sites', $this->callToolError($this->token, 'update_incident', ['id' => $incident->id, 'version' => $read['version'], 'report' => 'See [this](https://other.example).']));

        // Its own text, keeping the link the person put there.
        $this->update($incident, ['report' => 'The NFS mount hung. See [the graph](https://elsewhere.example/g).']);

        $activity = Activity::query()->where('subject_type', 'incident')->where('subject_id', $incident->id)->where('event', 'updated')->sole();
        $this->assertSame(['body' => 'ssh deploy@192.0.2.10 hung. See [the graph](https://elsewhere.example/g).'], $activity->properties['previous_texts']);
        $this->assertSame('deploy-agent', $activity->properties['via']);
    }

    public function test_a_text_longer_than_an_agent_reads_is_not_overwritten(): void
    {
        $incident = $this->incident(['root_cause' => str_repeat('a', Serializer::MAX_TEXT + 1)]);

        $this->assertStringStartsWith('root_cause: Deze tekst is langer', $this->callToolError($this->token, 'update_incident', ['id' => $incident->id, 'version' => $incident->version(), 'root_cause' => 'Short.']));
    }

    public function test_the_resolution_time_follows_the_status_unless_given(): void
    {
        $incident = $this->incident(['status' => IncidentStatus::Resolved, 'resolved_at' => '2026-11-09 11:00:00']);

        $this->update($incident, ['status' => 'open']);
        $this->assertSame([IncidentStatus::Open, null], [$incident->refresh()->status, $incident->resolved_at]);

        $this->update($incident, ['status' => 'resolved']);
        $this->assertSame([IncidentStatus::Resolved, '2026-11-09 12:00:00'], [$incident->refresh()->status, $incident->resolved_at->toDateTimeString()]);
    }

    public function test_an_update_keeps_one_server_or_project_at_least(): void
    {
        $incident = $this->incident();
        $project = Project::factory()->create();

        $this->assertStringStartsWith('Koppel het aan minstens één server of project', $this->callToolError($this->token, 'update_incident', ['id' => $incident->id, 'version' => $incident->version(), 'server_ids' => []]));

        $this->update($incident, ['server_ids' => [], 'project_ids' => [$project->id]]);
        $this->assertSame([[], [$project->id]], [$incident->servers()->pluck('servers.id')->all(), $incident->projects()->pluck('projects.id')->all()]);
    }

    public function test_an_incident_monitoring_opened_again_is_not_resolved_on_what_the_agent_read_before(): void
    {
        $incident = $this->incident(['status' => IncidentStatus::Monitoring]);
        $read = $incident->version();
        $check = Check::factory()->create();
        $check->events()->create(['from_state' => CheckState::Up, 'to_state' => CheckState::Down, 'occurred_at' => '2026-11-09 11:50:00', 'incident_id' => $incident->id]);
        $check->forceFill(['state' => CheckState::Up])->save();

        // Monitoring writes its recovery: the agent's version is gone.
        app(CheckIncidents::class)->recovered($check->events()->create(['from_state' => CheckState::Down, 'to_state' => CheckState::Up, 'occurred_at' => '2026-11-09 11:55:00']));
        $incident->update(['status' => IncidentStatus::Open]);

        $this->assertStringStartsWith('Dit incident is gewijzigd sinds je het las', $this->callToolError($this->token, 'resolve_incident', ['id' => $incident->id, 'version' => $read]));
        $this->assertSame(IncidentStatus::Open, $incident->refresh()->status);
    }

    public function test_an_impact_longer_than_an_agent_reads_is_not_overwritten(): void
    {
        $incident = $this->incident(['impact' => str_repeat('a', Serializer::MAX_TEXT + 1)]);

        $this->assertStringStartsWith('impact: Deze tekst is langer', $this->callToolError($this->token, 'update_incident', ['id' => $incident->id, 'version' => $incident->version(), 'impact' => null]));
    }

    public function test_an_edit_form_left_open_does_not_undo_what_an_agent_did(): void
    {
        $incident = $this->incident();
        $this->actingAs(User::factory()->withAppAuthentication()->create());
        $form = Livewire::test(EditIncident::class, ['record' => $incident->getRouteKey()]);

        $this->update($incident, ['root_cause' => 'NFS stalled.']);

        $form->fillForm(['title' => 'By hand'])->call('save')->assertNotified('Dit incident is intussen gewijzigd.');
        $this->assertSame(['Uploads fail', 'NFS stalled.'], [$incident->refresh()->title, $incident->root_cause]);
    }

    public function test_resolving_sets_when_and_why_and_a_repeat_says_nothing_changed(): void
    {
        $incident = $this->incident(['started_at' => '2026-11-09 10:00:00']);

        $this->assertStringContainsString('version', $this->callToolError($this->token, 'resolve_incident', ['id' => $incident->id]));

        $read = $incident->version();
        $answer = $this->callToolSuccessfully($this->token, 'resolve_incident', ['id' => $incident->id, 'version' => $read, 'root_cause' => 'NFS stalled.']);
        $incident->refresh();
        $this->assertSame([IncidentStatus::Resolved, '2026-11-09 12:00:00', 'NFS stalled.', 'resolved'], [$incident->status, $incident->resolved_at->toDateTimeString(), $incident->root_cause, $answer['incident']['status']]);

        // Called again with what it read before (its answer lost): the same answer.
        $again = $this->callToolSuccessfully($this->token, 'resolve_incident', ['id' => $incident->id, 'version' => $read, 'root_cause' => 'NFS stalled.']);
        $this->assertTrue($again['unchanged']);
        $this->assertSame('Dit incident is al opgelost. Wijzig of heropen het met update_incident.', $this->callToolError($this->token, 'resolve_incident', ['id' => $incident->id, 'version' => $incident->version(), 'root_cause' => 'Something else.']));
    }

    public function test_resolving_takes_a_time_that_has_passed_since_the_start(): void
    {
        $incident = $this->incident(['started_at' => '2026-11-09 10:00:00']);

        $version = $incident->version();
        $this->assertSame('resolved_at: Het kan niet vóór het begin liggen.', $this->callToolError($this->token, 'resolve_incident', ['id' => $incident->id, 'version' => $version, 'resolved_at' => '2026-11-09T09:00']));
        $this->assertSame('resolved_at: Het kan niet in de toekomst liggen.', $this->callToolError($this->token, 'resolve_incident', ['id' => $incident->id, 'version' => $version, 'resolved_at' => '2026-11-09T14:00']));

        $this->callToolSuccessfully($this->token, 'resolve_incident', ['id' => $incident->id, 'version' => $version, 'resolved_at' => '2026-11-09T12:30']);
        $this->assertSame('2026-11-09 11:30:00', $incident->refresh()->resolved_at->toDateTimeString());
    }

    public function test_the_change_tools_say_what_they_do(): void
    {
        $tools = collect($this->mcp($this->token, 'tools/list')->json('result.tools'))->keyBy('name');

        foreach (['update_incident', 'resolve_incident'] as $tool) {
            $this->assertSame(['destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], array_intersect_key($tools[$tool]['annotations'], ['readOnlyHint' => 0, 'destructiveHint' => 0, 'idempotentHint' => 0, 'openWorldHint' => 0]), $tool);
        }
        $this->assertEqualsCanonicalizing(['id', 'version'], $tools['update_incident']['inputSchema']['required']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function incident(array $attributes = []): Incident
    {
        $incident = Incident::factory()->create(['title' => 'Uploads fail', 'status' => IncidentStatus::Open, 'severity' => IncidentSeverity::Sev2, ...$attributes]);
        $incident->servers()->attach($this->server);

        return $incident;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function update(Incident $incident, array $changes): array
    {
        return $this->callToolSuccessfully($this->token, 'update_incident', ['id' => $incident->id, 'version' => $incident->fresh()->version(), ...$changes]);
    }
}
