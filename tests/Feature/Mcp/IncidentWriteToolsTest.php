<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgentAccess;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Mcp\Serializer;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class IncidentWriteToolsTest extends TestCase
{
    use ActsAsAgent, RefreshDatabase;

    private User $user;

    private string $token;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-09 12:00:30');
        config(['herdbook.mcp.enabled' => true]);
        $this->user = User::factory()->withAppAuthentication()->create(['name' => 'Ada']);
        [, $this->token] = $this->agentToken($this->user, AgentAccess::Write, name: 'deploy-agent');
        $this->server = Server::factory()->create(['name' => 'app-1']);
    }

    public function test_an_incident_opened_with_only_a_title_and_a_server_starts_now_open_and_names_its_agent(): void
    {
        $answer = $this->callToolSuccessfully($this->token, 'open_incident', ['title' => 'Uploads fail', 'server_ids' => [$this->server->id]]);

        $incident = Incident::query()->sole();
        $this->assertSame(
            ['Uploads fail', IncidentSeverity::Sev2, IncidentStatus::Open, IncidentSource::Manual, '2026-11-09', '2026-11-09 12:00:00', '2026-11-09 12:00:00', $this->user->id, 'deploy-agent'],
            [$incident->title, $incident->severity, $incident->status, $incident->source, $incident->occurred_on->toDateString(), $incident->started_at->toDateTimeString(), $incident->detected_at->toDateTimeString(), $incident->user_id, $incident->recorded_via],
        );
        $this->assertSame([$this->server->id], $incident->servers()->pluck('servers.id')->all());
        $this->assertSame(['Ada', 'deploy-agent', $incident->version()], [$answer['incident']['created_by'], $answer['incident']['via'], $answer['incident']['version']]);
        $this->assertSame([], $answer['other_unresolved']);
    }

    public function test_an_incident_takes_what_it_is_given(): void
    {
        $project = Project::factory()->create();

        $this->callToolSuccessfully($this->token, 'open_incident', [
            'title' => '  Storage  stall ',
            'severity' => 'sev1',
            'started_at' => '2026-11-08T21:00',
            'detected_at' => '2026-11-08T21:05',
            'impact' => 'Uploads failed for an hour.',
            'report' => 'NFS stalled; see [app-1](/servers/1).',
            'project_ids' => [$project->id],
        ]);

        $incident = Incident::query()->sole();
        $this->assertSame(
            ['Storage stall', IncidentSeverity::Sev1, '2026-11-08', '2026-11-08 20:00:00', '2026-11-08 20:05:00', 'Uploads failed for an hour.', 'NFS stalled; see [app-1](/servers/1).'],
            [$incident->title, $incident->severity, $incident->occurred_on->toDateString(), $incident->started_at->toDateTimeString(), $incident->detected_at->toDateTimeString(), $incident->impact, $incident->body],
        );
    }

    public function test_the_answer_names_the_unresolved_incidents_of_the_same_servers_to_join_instead(): void
    {
        $draft = Incident::factory()->create(['source' => IncidentSource::Check, 'status' => IncidentStatus::Open]);
        $draft->servers()->attach($this->server);
        Incident::factory()->resolved()->create()->servers()->attach($this->server);

        $answer = $this->callToolSuccessfully($this->token, 'open_incident', ['title' => 'Uploads fail', 'server_ids' => [$this->server->id]]);

        $this->assertSame([$draft->id], array_column($answer['other_unresolved'], 'id'));
    }

    public function test_an_incident_linked_to_no_server_or_project_names_no_others(): void
    {
        $alone = Incident::factory()->create();
        Incident::factory()->create()->servers()->attach($this->server);

        $answer = $this->callToolSuccessfully($this->token, 'add_timeline_entry', ['id' => $alone->id, 'note' => 'Looked at it.']);

        $this->assertSame([], $answer['other_unresolved']);
    }

    public function test_a_call_tried_again_after_its_answer_was_lost_writes_nothing_twice(): void
    {
        $first = $this->callToolSuccessfully($this->token, 'open_incident', ['title' => 'Uploads fail', 'server_ids' => [$this->server->id]]);
        // Also when monitoring or a person changed it meanwhile.
        Incident::query()->sole()->update(['severity' => IncidentSeverity::Sev1]);
        Incident::query()->sole()->servers()->attach(Server::factory()->create());
        $again = $this->callToolSuccessfully($this->token, 'open_incident', ['title' => ' Uploads  fail', 'server_ids' => [$this->server->id]]);
        $this->assertSame([1, $first['incident']['id'], true], [Incident::count(), $again['incident']['id'], $again['unchanged']]);

        $id = $first['incident']['id'];
        foreach (range(1, 2) as $try) {
            $entry = $this->callToolSuccessfully($this->token, 'add_timeline_entry', ['id' => $id, 'note' => 'Restarted php-fpm.']);
            $action = $this->callToolSuccessfully($this->token, 'add_incident_action', ['id' => $id, 'description' => 'Alert on NFS latency']);
        }
        $this->assertSame([1, 1, true, true], [Incident::query()->sole()->timelineEntries()->count(), Incident::query()->sole()->actions()->count(), $entry['unchanged'], $action['unchanged']]);

        // Later, the same note is a second entry: it happened again.
        Date::setTestNow(now()->addMinutes(11));
        $later = $this->callToolSuccessfully($this->token, 'add_timeline_entry', ['id' => $id, 'note' => 'Restarted php-fpm.']);
        $this->assertSame([2, null], [Incident::query()->sole()->timelineEntries()->count(), $later['unchanged'] ?? null]);
    }

    public function test_only_the_same_call_by_the_same_agent_counts_as_tried_again(): void
    {
        $open = fn (array $arguments): array => $this->callToolSuccessfully($this->token, 'open_incident', ['title' => 'Uploads fail', 'server_ids' => [$this->server->id], ...$arguments]);

        $id = $open([])['incident']['id'];
        $this->assertTrue($open(['severity' => 'sev2'])['unchanged']);
        // Anything else that differs is another incident: compared as written, not as MySQL compares text.
        foreach ([['severity' => 'sev1'], ['title' => 'uploads fail'], ['server_ids' => [$this->server->id, Server::factory()->create()->id]], ['impact' => 'Slow'], ['started_at' => '2026-11-09T11:00']] as $other) {
            $this->assertArrayNotHasKey('unchanged', $open($other));
        }
        $this->assertTrue($open(['started_at' => '2026-11-09T11:00'])['unchanged']);
        $this->assertSame(6, Incident::count());

        $entry = fn (array $arguments, ?string $token = null): array => $this->callToolSuccessfully($token ?? $this->token, 'add_timeline_entry', ['id' => $id, 'note' => 'Restarted php-fpm.', ...$arguments]);
        $entry(['occurred_at' => '2026-11-09T12:50']);
        $this->assertTrue($entry(['occurred_at' => '2026-11-09T12:50'])['unchanged']);
        // The same note, when it happened again; in another case; through another agent.
        [, $other] = $this->agentToken($this->user, AgentAccess::Write, name: 'other-agent');
        foreach ([[['occurred_at' => '2026-11-09T12:55']], [['note' => 'restarted php-fpm.']], [[], $other]] as $arguments) {
            $this->assertArrayNotHasKey('unchanged', $entry(...$arguments));
        }
        $this->assertSame(4, Incident::query()->find($id)->timelineEntries()->count());

        $action = fn (array $arguments): array => $this->callToolSuccessfully($this->token, 'add_incident_action', ['id' => $id, 'description' => 'Alert on NFS latency', ...$arguments]);
        $first = $action(['due_on' => '2026-11-20'])['action']['id'];
        $this->assertArrayNotHasKey('unchanged', $action(['due_on' => '2026-11-27']));
        $this->callToolSuccessfully($this->token, 'complete_incident_action', ['id' => $first]);
        // Completed, the same action item is a new one.
        $this->assertArrayNotHasKey('unchanged', $action(['due_on' => '2026-11-20']));
        $this->assertSame(3, Incident::query()->find($id)->actions()->count());
    }

    public function test_the_day_it_is_filed_under_is_the_start_in_the_display_timezone(): void
    {
        // 23:30 in UTC is half past midnight the next day in Amsterdam.
        Date::setTestNow('2026-11-09 23:30:00');

        $this->callToolSuccessfully($this->token, 'open_incident', ['title' => 'Uploads fail', 'server_ids' => [$this->server->id]]);

        $this->assertSame('2026-11-10', Incident::query()->sole()->occurred_on->toDateString());
    }

    public function test_an_action_item_written_in_the_panel_has_its_author_and_no_agent(): void
    {
        $this->actingAs($this->user);

        $action = Incident::factory()->create()->actions()->create(['description' => 'Alert on NFS latency']);

        $this->assertSame([$this->user->id, null], [$action->user_id, $action->recorded_via]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'no links' => [['server_ids' => []], 'Koppel het aan minstens één server of project'],
            'no title' => [['title' => null], 'Het veld title is verplicht.'],
            'a future start' => [['started_at' => '2026-11-10T09:00'], 'started_at: Het kan niet in de toekomst liggen.'],
            'detected before the start' => [['started_at' => '2026-11-08T21:00', 'detected_at' => '2026-11-08T20:00'], 'detected_at: Het kan niet vóór het begin liggen.'],
            'before 1970' => [['started_at' => '1969-12-31T10:00'], 'started_at: Kies een moment tussen 1970 en 2038.'],
            'a future day' => [['occurred_on' => '2026-11-10'], 'occurred_on'],
            'a severity there is none of' => [['severity' => 'sev4'], 'severity'],
            'a mask in the title' => [['title' => 'Ping [adres van app-1] fails'], 'title: Dit bevat een masker'],
            'a password in the impact' => [['impact' => 'DB_PASSWORD=a1B2a1B2a1B2a1B2a1B2 leaked'], 'impact: Dit lijkt een wachtwoord of token.'],
            'a link elsewhere in the report' => [['report' => 'See [status](https://elsewhere.example).'], 'report: Geen links naar andere sites'],
            'an image in the report' => [['report' => '![x](https://elsewhere.example/x.png)'], 'report: Geen afbeeldingen of HTML'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('refusals')]
    public function test_opening_refuses_what_the_forms_and_the_rules_for_agents_refuse(array $arguments, string $message): void
    {
        $error = $this->callToolError($this->token, 'open_incident', [...['title' => 'Uploads fail', 'server_ids' => [$this->server->id]], ...$arguments]);

        $this->assertStringContainsString($message, $error);
        $this->assertSame(0, Incident::count());
    }

    public function test_a_timeline_entry_is_added_now_or_when_it_happened_by_whom_and_through_which_agent(): void
    {
        $incident = Incident::factory()->resolved()->create();

        $answer = $this->callToolSuccessfully($this->token, 'add_timeline_entry', ['id' => $incident->id, 'note' => 'Restarted php-fpm: no effect.']);
        $this->callToolSuccessfully($this->token, 'add_timeline_entry', ['id' => $incident->id, 'note' => 'Noticed slow uploads.', 'occurred_at' => '2026-11-09T11:30']);

        $this->assertSame(['occurred_at' => '2026-11-09T13:00:00+01:00', 'note' => 'Restarted php-fpm: no effect.', 'truncated' => false, 'created_by' => 'Ada', 'via' => 'deploy-agent'], $answer['entry']);
        $this->assertSame($incident->id, $answer['incident']['id']);
        $this->assertSame(['Noticed slow uploads.', 'Restarted php-fpm: no effect.'], $incident->timelineEntries()->pluck('note')->all());
    }

    public function test_a_timeline_entry_refuses_the_future_long_notes_and_masks(): void
    {
        $incident = Incident::factory()->create();

        $this->assertSame('occurred_at: Het kan niet in de toekomst liggen.', $this->callToolError($this->token, 'add_timeline_entry', ['id' => $incident->id, 'note' => 'x', 'occurred_at' => '2026-11-09T14:00']));
        $this->assertStringContainsString('1000', $this->callToolError($this->token, 'add_timeline_entry', ['id' => $incident->id, 'note' => str_repeat('x', Serializer::MAX_NOTE + 1)]));
        $this->assertStringStartsWith('note: Dit bevat een masker', $this->callToolError($this->token, 'add_timeline_entry', ['id' => $incident->id, 'note' => 'ssh to [address] failed']));
        $this->assertSame('Er is geen incident met id 999. list_incidents vindt ze.', $this->callToolError($this->token, 'add_timeline_entry', ['id' => 999, 'note' => 'x']));
        $this->assertSame(0, $incident->timelineEntries()->count());
    }

    public function test_an_action_item_is_added_with_its_author_and_completed_once(): void
    {
        $incident = Incident::factory()->create();

        $added = $this->callToolSuccessfully($this->token, 'add_incident_action', ['id' => $incident->id, 'description' => 'Alert on NFS latency', 'due_on' => '2026-11-20']);
        $action = $incident->actions()->sole();
        $this->assertSame(['Alert on NFS latency', '2026-11-20', $this->user->id, 'deploy-agent'], [$action->description, $action->due_on->toDateString(), $action->user_id, $action->recorded_via]);
        $this->assertSame(['id' => $action->id, 'description' => 'Alert on NFS latency', 'due_on' => '2026-11-20', 'done_at' => null, 'overdue' => false, 'created_by' => 'Ada', 'via' => 'deploy-agent', 'completed_by' => null, 'completed_via' => null], $added['action']);
        $this->assertSame(1, $added['incident']['open_actions']);

        $done = $this->callToolSuccessfully($this->token, 'complete_incident_action', ['id' => $action->id]);
        Date::setTestNow('2026-11-09 13:00:00');
        $again = $this->callToolSuccessfully($this->token, 'complete_incident_action', ['id' => $action->id]);

        $this->assertSame(['2026-11-09T13:00:30+01:00', null, '2026-11-09T13:00:30+01:00', true], [$done['action']['done_at'], $done['unchanged'] ?? null, $again['action']['done_at'], $again['unchanged']]);
        $this->assertSame(['Ada', 'deploy-agent'], [$done['action']['completed_by'], $done['action']['completed_via']]);
        $this->assertSame([$this->user->id, 'deploy-agent'], [$action->refresh()->completed_by_id, $action->completed_via]);
        $this->assertSame(0, $again['incident']['open_actions']);
        $this->assertSame('Er is geen actiepunt met id 999. get_incident noemt ze.', $this->callToolError($this->token, 'complete_incident_action', ['id' => 999]));
    }

    public function test_a_read_token_sees_none_of_the_writing_tools_and_they_say_what_they_do(): void
    {
        [, $reader] = $this->agentToken(access: AgentAccess::Read);
        $names = array_column($this->mcp($reader, 'tools/list')->json('result.tools'), 'name');

        foreach (['open_incident', 'add_timeline_entry', 'add_incident_action', 'complete_incident_action'] as $tool) {
            $this->assertNotContains($tool, $names);
            $this->callTool($reader, $tool, ['id' => 1])->assertJsonPath('error.code', -32602);
        }

        $tools = collect($this->mcp($this->token, 'tools/list')->json('result.tools'))->keyBy('name');
        $hints = fn (string $tool): array => array_intersect_key($tools[$tool]['annotations'], ['readOnlyHint' => 0, 'destructiveHint' => 0, 'idempotentHint' => 0, 'openWorldHint' => 0]);

        foreach (['open_incident', 'add_timeline_entry', 'add_incident_action'] as $tool) {
            $this->assertSame(['destructiveHint' => false, 'openWorldHint' => false], $hints($tool), $tool);
        }
        $this->assertSame(['destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], $hints('complete_incident_action'));
    }
}
