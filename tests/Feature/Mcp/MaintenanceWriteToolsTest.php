<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgentAccess;
use App\Enums\MaintenanceStatus;
use App\Filament\Resources\Maintenances\Pages\CreateMaintenance;
use App\Filament\Resources\Maintenances\Pages\ViewMaintenance;
use App\Mcp\Serializer;
use App\Models\Maintenance;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class MaintenanceWriteToolsTest extends TestCase
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
        $this->server = Server::factory()->create(['name' => 'app-1']);
    }

    public function test_scheduling_stores_the_work_with_its_links_by_the_account_through_the_agent(): void
    {
        $project = Project::factory()->create(['name' => 'Shop']);

        $answer = $this->callToolSuccessfully($this->token, 'schedule_maintenance', [
            'tasks' => ['updates', 'reboot'],
            'starts_at' => '2026-11-12T22:00',
            'ends_at' => '2026-11-12T23:30',
            'plan' => "1. `apt upgrade`\n2. Reboot",
            'server_ids' => [$this->server->id],
            'project_ids' => [$project->id],
        ]);

        $maintenance = Maintenance::query()->sole();
        $this->assertSame([MaintenanceStatus::Planned, 'Updates en herstart', $this->user->id, 'deploy-agent'], [$maintenance->status, $maintenance->title, $maintenance->user_id, $maintenance->recorded_via]);
        // Without an offset: Amsterdam time, stored in UTC.
        $this->assertSame(['2026-11-12 21:00:00', '2026-11-12 22:30:00'], [$maintenance->starts_at->toDateTimeString(), $maintenance->ends_at->toDateTimeString()]);
        $this->assertSame("1. `apt upgrade`\n2. Reboot", $maintenance->plan);
        $this->assertSame([$this->server->id], $maintenance->servers()->pluck('servers.id')->all());
        $this->assertSame([$project->id], $maintenance->projects()->pluck('projects.id')->all());

        $this->assertSame(['maintenance', 'other_planned'], array_keys($answer));
        $this->assertSame(Serializer::maintenance($maintenance->load(Serializer::MAINTENANCE_RELATIONS)), $answer['maintenance']);
        $this->assertSame(['2026-11-12T22:00:00+01:00', 'deploy-agent', 'Ada'], [$answer['maintenance']['starts_at'], $answer['maintenance']['via'], $answer['maintenance']['created_by']]);
    }

    public function test_the_generated_title_is_in_the_language_of_the_account(): void
    {
        $this->user->update(['locale' => 'en']);

        $answer = $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['updates', 'reboot'], 'server_ids' => [$this->server->id]]);

        $this->assertSame('Updates and reboot', $answer['maintenance']['title']);
    }

    /** @return array<string, array{string, string}> */
    public static function moments(): array
    {
        return [
            'no offset' => ['2026-11-12T22:00', '2026-11-12 21:00:00'],
            'seconds, dropped as in the panel' => ['2026-11-12T22:00:30', '2026-11-12 21:00:00'],
            'a space' => ['2026-11-12 22:00', '2026-11-12 21:00:00'],
            'Z' => ['2026-11-12T22:00:00Z', '2026-11-12 22:00:00'],
            'an offset' => ['2026-11-12T22:00:00+00:00', '2026-11-12 22:00:00'],
            'milliseconds' => ['2026-11-12T22:00:00.000Z', '2026-11-12 22:00:00'],
        ];
    }

    #[DataProvider('moments')]
    public function test_a_moment_is_read_in_its_forms(string $given, string $stored): void
    {
        $this->callToolSuccessfully($this->token, 'schedule_maintenance', ['tasks' => ['updates'], 'starts_at' => $given, 'server_ids' => [$this->server->id]]);

        $this->assertSame($stored, Maintenance::query()->sole()->starts_at->toDateTimeString());
    }

    public function test_logging_is_done_work_that_started_now_unless_it_says_otherwise(): void
    {
        $now = $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['inspection'], 'report' => 'All quiet.', 'server_ids' => [$this->server->id]]);
        $earlier = $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['updates'], 'starts_at' => '2026-11-09T08:00', 'server_ids' => [$this->server->id]]);

        $this->assertSame(['done', '2026-11-09T13:00:00+01:00'], [$now['maintenance']['status'], $now['maintenance']['starts_at']]);
        $this->assertSame('2026-11-09T08:00:00+01:00', $earlier['maintenance']['starts_at']);
        $this->assertSame('All quiet.', Maintenance::query()->find($now['maintenance']['id'])->report);
    }

    public function test_the_answer_names_the_planned_maintenance_it_shares_a_server_or_project_with(): void
    {
        $project = Project::factory()->create();
        $onServer = Maintenance::factory()->planned()->create();
        $onServer->servers()->attach($this->server);
        $onProject = Maintenance::factory()->planned()->create();
        $onProject->projects()->attach($project);
        Maintenance::factory()->planned()->create();
        Maintenance::factory()->create()->servers()->attach($this->server);

        $answer = $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['updates'], 'server_ids' => [$this->server->id], 'project_ids' => [$project->id]]);

        $this->assertEqualsCanonicalizing([$onServer->id, $onProject->id], array_column($answer['other_planned'], 'id'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'done in the future' => [['tool' => 'log_maintenance', 'starts_at' => '2026-11-10T09:00'], 'starts_at: Wat al gedaan is, kan niet in de toekomst liggen.'],
            'end before start' => [['starts_at' => '2026-11-12T22:00', 'ends_at' => '2026-11-12T21:00'], 'ends_at: Het einde kan niet vóór het begin liggen.'],
            'after 2038' => [['starts_at' => '2039-01-01T10:00'], 'starts_at: Kies een moment tussen 1970 en 2038.'],
            'year 0026' => [['starts_at' => '0026-11-12T10:00'], 'Kies een moment tussen 1970 en 2038.'],
            'tomorrow' => [['starts_at' => 'tomorrow'], 'starts_at: Geef een moment als 2026-11-12T22:00'],
            'no such month' => [['starts_at' => '2026-13-45T25:61'], 'Geef een moment als 2026-11-12T22:00'],
            'no such day' => [['starts_at' => '2026-02-30T10:00'], 'Geef een moment als 2026-11-12T22:00'],
            'midnight as 24:00' => [['starts_at' => '2026-11-12T24:00'], 'Geef een moment als 2026-11-12T22:00'],
            'something after it' => [['starts_at' => '2026-11-12T22:00 +1 day'], 'Geef een moment als 2026-11-12T22:00'],
            'no such offset' => [['starts_at' => '2026-11-12T22:00+99:99'], 'Geef een moment als 2026-11-12T22:00'],
            'only an end in the past' => [['tool' => 'log_maintenance', 'starts_at' => null, 'ends_at' => '2026-11-09T08:00'], 'Het einde kan niet vóór het begin liggen.'],
            'no start' => [['starts_at' => null], 'Het veld starts_at is verplicht.'],
            'no links' => [['server_ids' => []], 'Koppel het aan minstens één server of project'],
            'an unknown server' => [['server_ids' => [999]], 'Geen server heeft id 999.'],
            'a server twice' => [['server_ids' => ['SERVER', 'SERVER']], 'Het veld server_ids heeft een dubbele waarde.'],
            'a boolean for an id' => [['server_ids' => [true]], 'Het veld server_ids moet een getal zijn.'],
            'a plan for done work' => [['tool' => 'log_maintenance', 'plan' => 'Steps'], 'Onbekend argument: plan. Deze tool neemt: tasks, title, starts_at, ends_at, server_ids, project_ids, report.'],
            'work as an object' => [['tasks' => ['a' => 'updates']], 'Het veld tasks moet een lijst zijn.'],
            'more work than there is' => [['tasks' => ['updates', 'reboot', 'inspection', 'upgrade', 'other', 'updates']], 'Het veld tasks mag niet meer dan 5 items bevatten.'],
            'no work' => [['tasks' => []], 'Het veld tasks is verplicht.'],
            'unknown work' => [['tasks' => ['dusting']], 'De geselecteerde tasks is ongeldig.'],
            'the same work twice' => [['tasks' => ['updates', 'updates']], 'Het veld tasks heeft een dubbele waarde.'],
            'a long title' => [['title' => '255+'], 'Het veld title mag niet meer dan 255 tekens bevatten.'],
            'a long plan' => [['plan' => 'MAX_TEXT+1'], 'Het veld plan mag niet meer dan 10000 tekens bevatten.'],
            'a private key' => [['plan' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3Bl"], 'Vaultwarden'],
            'a password' => [['plan' => 'Set DB_PASSWORD=a1B2a1B2a1B2a1B2a1B2 in .env'], 'plan: Dit lijkt een wachtwoord of token.'],
            'a token in the title' => [['title' => 'Rotate DB_PASSWORD=a1B2a1B2a1B2a1B2a1B2'], 'title: Dit lijkt een wachtwoord of token.'],
            'an image' => [['plan' => '![x](https://elsewhere.example/x.png)'], 'plan: Geen afbeeldingen of HTML'],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('refusals')]
    public function test_wrong_work_is_refused_in_the_words_of_the_forms(array $changes, string $message): void
    {
        $tool = $changes['tool'] ?? 'schedule_maintenance';
        unset($changes['tool']);
        $arguments = [...['tasks' => ['updates'], 'starts_at' => '2026-11-12T22:00', 'server_ids' => [$this->server->id]], ...$changes];
        $arguments = array_map(fn (mixed $value): mixed => match (true) {
            $value === '255+' => str_repeat('a', 256),
            $value === 'MAX_TEXT+1' => str_repeat('a', Serializer::MAX_TEXT + 1),
            $value === ['SERVER', 'SERVER'] => [$this->server->id, $this->server->id],
            default => $value,
        }, array_filter($arguments, fn (mixed $value): bool => $value !== null));

        $this->assertStringContainsString($message, $this->callToolError($this->token, $tool, $arguments));
        $this->assertSame(0, Maintenance::query()->count());
    }

    public function test_a_huge_list_of_work_is_refused_by_its_size_before_its_items_are_looked_at(): void
    {
        // One message: the rules for each item never ran.
        $this->assertSame('Het veld tasks mag niet meer dan 5 items bevatten.', $this->callToolError($this->token, 'log_maintenance', ['tasks' => array_fill(0, 20000, 'updates'), 'server_ids' => [$this->server->id]]));
    }

    public function test_an_end_in_the_future_is_fine_for_work_done_now(): void
    {
        $answer = $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['upgrade'], 'ends_at' => '2026-11-09T15:00', 'server_ids' => [$this->server->id]]);

        $this->assertSame(['2026-11-09T13:00:00+01:00', '2026-11-09T15:00:00+01:00'], [$answer['maintenance']['starts_at'], $answer['maintenance']['ends_at']]);
    }

    public function test_scheduling_names_other_planned_work_but_not_itself_due_first_and_at_most_ten(): void
    {
        $later = Maintenance::factory()->planned()->create(['starts_at' => '2026-11-20 09:00:00']);
        $sooner = Maintenance::factory()->planned()->create(['starts_at' => '2026-11-15 09:00:00']);
        foreach ([$later, $sooner] as $planned) {
            $planned->servers()->attach($this->server);
        }

        $answer = $this->callToolSuccessfully($this->token, 'schedule_maintenance', ['tasks' => ['updates'], 'starts_at' => '2026-11-12T22:00', 'server_ids' => [$this->server->id]]);

        $this->assertSame([$sooner->id, $later->id], array_column($answer['other_planned'], 'id'));

        foreach (range(1, 12) as $index) {
            Maintenance::factory()->planned()->create()->servers()->attach($this->server);
        }
        $this->assertCount(10, $this->callToolSuccessfully($this->token, 'schedule_maintenance', ['tasks' => ['updates'], 'starts_at' => '2026-11-12T22:00', 'server_ids' => [$this->server->id]])['other_planned']);
    }

    public function test_a_failure_halfway_stores_nothing_and_says_nothing_of_the_database(): void
    {
        config(['app.debug' => false]);
        Maintenance::created(fn () => throw new RuntimeException('SQLSTATE[23000]: insert into maintainables failed'));

        $message = $this->callToolError($this->token, 'log_maintenance', ['tasks' => ['updates'], 'server_ids' => [$this->server->id]]);

        $this->assertSame(0, Maintenance::query()->count());
        foreach (['SQL', 'maintainables', 'insert', 'Exception'] as $hidden) {
            $this->assertStringNotContainsStringIgnoringCase($hidden, $message);
        }
    }

    public function test_the_input_schema_says_what_is_required(): void
    {
        $tools = collect($this->mcp($this->token, 'tools/list')->json('result.tools'))->keyBy('name');

        $this->assertEqualsCanonicalizing(['tasks', 'starts_at'], $tools['schedule_maintenance']['inputSchema']['required']);
        $this->assertSame(['tasks'], $tools['log_maintenance']['inputSchema']['required']);
    }

    public function test_an_argument_a_read_tool_does_not_take_is_refused_too(): void
    {
        $this->assertSame('Onbekend argument: server_id. Deze tool neemt: status, task, server_ids, project_ids, limit.', $this->callToolError($this->token, 'list_maintenance', ['server_id' => $this->server->id]));
    }

    public function test_the_panel_and_an_agent_refuse_the_same_moments_in_the_same_words(): void
    {
        $this->actingAs($this->user);

        foreach ([
            ['status' => MaintenanceStatus::Done, 'starts_at' => '2026-11-10 09:00', 'field' => 'starts_at', 'tool' => 'log_maintenance'],
            ['status' => MaintenanceStatus::Planned, 'starts_at' => '2040-01-01 10:00', 'field' => 'starts_at', 'tool' => 'schedule_maintenance'],
            ['status' => MaintenanceStatus::Planned, 'starts_at' => '0026-11-01 10:00', 'field' => 'starts_at', 'tool' => 'schedule_maintenance'],
            ['status' => MaintenanceStatus::Planned, 'starts_at' => '2026-11-12 22:00', 'ends_at' => '2026-11-12 21:00', 'field' => 'ends_at', 'tool' => 'schedule_maintenance'],
        ] as $case) {
            $form = Livewire::test(CreateMaintenance::class)
                ->fillForm(['tasks' => ['updates'], 'status' => $case['status'], 'starts_at' => $case['starts_at'], 'ends_at' => $case['ends_at'] ?? null])
                ->call('create');
            $panel = $form->errors()->first("data.{$case['field']}");

            $agent = $this->callToolError($this->token, $case['tool'], array_filter([
                'tasks' => ['updates'],
                'starts_at' => str_replace(' ', 'T', $case['starts_at']),
                'ends_at' => isset($case['ends_at']) ? str_replace(' ', 'T', $case['ends_at']) : null,
                'server_ids' => [$this->server->id],
            ]));

            $this->assertNotEmpty($panel, json_encode($case));
            $this->assertStringContainsString($panel, $agent, json_encode($case));
        }
    }

    public function test_via_stays_when_the_account_is_gone(): void
    {
        $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['updates'], 'server_ids' => [$this->server->id]]);

        $this->user->delete();

        $this->assertSame('via deploy-agent', Maintenance::query()->sole()->recordedBy());
    }

    public function test_a_read_token_sees_and_calls_no_write_tool(): void
    {
        [, $reader] = $this->agentToken(access: AgentAccess::Read);

        $tools = array_column($this->mcp($reader, 'tools/list')->json('result.tools'), 'name');
        $this->assertNotContains('schedule_maintenance', $tools);
        $this->assertNotContains('log_maintenance', $tools);

        $this->callTool($reader, 'log_maintenance', ['tasks' => ['updates'], 'server_ids' => [$this->server->id]])->assertJsonPath('error.code', -32602);
        $this->assertSame(0, Maintenance::query()->count());
    }

    public function test_the_write_tools_say_they_change_but_destroy_nothing(): void
    {
        $tools = collect($this->mcp($this->token, 'tools/list')->json('result.tools'))->keyBy('name');

        foreach (['schedule_maintenance', 'log_maintenance'] as $name) {
            $this->assertSame(['destructiveHint' => false, 'openWorldHint' => false], array_intersect_key($tools[$name]['annotations'], ['destructiveHint' => 0, 'openWorldHint' => 0]), $name);
            $this->assertArrayNotHasKey('readOnlyHint', $tools[$name]['annotations'], $name);
        }
    }

    public function test_the_panel_shows_who_recorded_it_and_through_which_agent(): void
    {
        $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['updates'], 'server_ids' => [$this->server->id]]);
        $maintenance = Maintenance::query()->sole();

        $this->actingAs($this->user);
        Livewire::test(ViewMaintenance::class, ['record' => $maintenance->getRouteKey()])->assertSee('Ada via deploy-agent');

        $created = Activity::query()->where('subject_type', 'maintenance')->where('event', 'created')->sole();
        $this->assertTrue($created->causer->is($this->user));
    }

    public function test_a_blank_title_is_made_from_the_work_and_one_given_is_trimmed(): void
    {
        $blank = $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['reboot'], 'title' => '  ', 'server_ids' => [$this->server->id]]);
        $given = $this->callToolSuccessfully($this->token, 'log_maintenance', ['tasks' => ['reboot'], 'title' => "  Kernel\n 6.12 ", 'server_ids' => [$this->server->id]]);

        $this->assertSame(['Herstart', 'Kernel 6.12'], [$blank['maintenance']['title'], $given['maintenance']['title']]);
    }
}
