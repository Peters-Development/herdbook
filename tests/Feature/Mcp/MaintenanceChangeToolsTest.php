<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgentAccess;
use App\Enums\MaintenanceStatus;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\Maintenances\Pages\EditMaintenance;
use App\Filament\Resources\Maintenances\Pages\ViewMaintenance;
use App\Filament\Support\ActivityPresenter;
use App\Mcp\Serializer;
use App\Models\AgentToken;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ActsAsAgent;
use Tests\TestCase;

class MaintenanceChangeToolsTest extends TestCase
{
    use ActsAsAgent, RefreshDatabase;

    private User $user;

    private AgentToken $agent;

    private string $token;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-09 12:00:00');
        config(['herdbook.mcp.enabled' => true]);
        $this->user = User::factory()->withAppAuthentication()->create(['name' => 'Ada']);
        [$this->agent, $this->token] = $this->agentToken($this->user, AgentAccess::Write, name: 'deploy-agent');
        $this->server = Server::factory()->create();
    }

    public function test_an_update_changes_only_what_it_gives(): void
    {
        $maintenance = $this->planned(['title' => 'Upgrade', 'plan' => 'Old plan', 'ends_at' => '2026-11-12 23:00:00']);
        $project = Project::factory()->create();
        $other = Server::factory()->create();

        $answer = $this->update($maintenance, [
            'starts_at' => '2026-11-13T22:00',
            'ends_at' => null,
            'plan' => 'New plan',
            'server_ids' => [$other->id],
            'project_ids' => [$project->id],
        ]);

        $maintenance->refresh();
        $this->assertSame(['Upgrade', ['updates'], '2026-11-13 21:00:00', null, 'New plan'], [$maintenance->title, $maintenance->tasks, $maintenance->starts_at->toDateTimeString(), $maintenance->ends_at, $maintenance->plan]);
        $this->assertSame([$other->id], $maintenance->servers()->pluck('servers.id')->all());
        $this->assertSame([$project->id], $maintenance->projects()->pluck('projects.id')->all());
        $this->assertSame(['2026-11-13T22:00:00+01:00', $maintenance->version()], [$answer['maintenance']['starts_at'], $answer['maintenance']['version']]);
    }

    public function test_an_update_with_only_links_and_an_empty_list_of_one_kind(): void
    {
        $maintenance = $this->planned();
        $project = Project::factory()->create();
        $maintenance->projects()->attach($project);

        $this->update($maintenance, ['project_ids' => []]);

        $this->assertSame([[$this->server->id], []], [$maintenance->servers()->pluck('servers.id')->all(), $maintenance->projects()->pluck('projects.id')->all()]);
    }

    public function test_an_update_of_done_work_keeps_to_the_rules_of_done_work(): void
    {
        $done = Maintenance::factory()->create(['starts_at' => '2026-11-08 09:00:00']);
        $done->servers()->attach($this->server);

        $this->assertSame('starts_at: Wat al gedaan is, kan niet in de toekomst liggen.', $this->callToolError($this->token, 'update_maintenance', ['id' => $done->id, 'version' => $done->version(), 'starts_at' => '2026-11-10T09:00']));

        $this->update($done, ['report' => 'Kernel 6.12.']);
        $this->assertSame('Kernel 6.12.', $done->refresh()->report);
    }

    public function test_an_update_is_checked_as_a_whole(): void
    {
        $maintenance = $this->planned(['ends_at' => '2026-11-12 23:00:00']);
        $refused = fn (array $changes): string => $this->callToolError($this->token, 'update_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->version(), ...$changes]);

        // The new start after the stored end, the new end before the stored start.
        $this->assertSame('ends_at: Het einde kan niet vóór het begin liggen.', $refused(['starts_at' => '2026-11-13T09:00']));
        $this->assertSame('ends_at: Het einde kan niet vóór het begin liggen.', $refused(['ends_at' => '2026-11-12T20:00']));
        // No links left.
        $this->assertStringContainsString('Koppel het aan minstens één server of project', $refused(['server_ids' => []]));
        // Tasks and the start can't be emptied; null is no list.
        $this->assertSame('Het veld tasks is verplicht.', $refused(['tasks' => []]));
        $this->assertSame('Het veld starts_at is verplicht.', $refused(['starts_at' => null]));
        $this->assertSame('Het veld server_ids moet een lijst zijn.', $refused(['server_ids' => null]));
        // Without its version.
        $this->assertSame('Het veld version is verplicht.', $this->callToolError($this->token, 'update_maintenance', ['id' => $maintenance->id, 'title' => 'Other']));

        $this->assertSame('2026-11-12 21:00:00', $maintenance->refresh()->starts_at->toDateTimeString());
        $this->assertSame(1, $maintenance->servers()->count());
    }

    public function test_an_update_over_a_change_made_since_it_was_read_is_refused(): void
    {
        $maintenance = $this->planned(['plan' => 'Old plan']);
        $read = $this->callToolSuccessfully($this->token, 'get_maintenance', ['id' => $maintenance->id])['version'];

        // A person changes the plan in the panel meanwhile.
        $maintenance->update(['plan' => 'Their plan']);

        $this->assertSame('Dit onderhoud is gewijzigd sinds je het las (nu: planned). Lees het opnieuw met get_maintenance.', $this->callToolError($this->token, 'update_maintenance', ['id' => $maintenance->id, 'version' => $read, 'plan' => 'My plan']));
        $this->assertSame('Their plan', $maintenance->refresh()->plan);

        // So are links someone changed since.
        $read = $maintenance->version();
        $maintenance->servers()->attach(Server::factory()->create());
        $this->callToolError($this->token, 'update_maintenance', ['id' => $maintenance->id, 'version' => $read, 'title' => 'Mine']);
        $this->assertNotSame('Mine', $maintenance->refresh()->title);
    }

    public function test_a_change_between_reading_and_locking_is_not_overwritten_and_links_stay(): void
    {
        $maintenance = $this->planned();
        $version = $maintenance->version();
        $this->changeWhenLoaded($maintenance, ['status' => MaintenanceStatus::Done->value]);

        $this->assertStringContainsString('(nu: done)', $this->callToolError($this->token, 'update_maintenance', ['id' => $maintenance->id, 'version' => $version, 'server_ids' => [Server::factory()->create()->id]]));
        $this->assertSame([$this->server->id], $maintenance->servers()->pluck('servers.id')->all());
    }

    public function test_maintenance_deleted_between_reading_and_locking_is_not_found(): void
    {
        foreach (['update_maintenance', 'complete_maintenance', 'cancel_maintenance'] as $tool) {
            $maintenance = $this->planned();
            $version = $maintenance->version();
            $this->deleteWhenLoaded($maintenance);

            $this->assertSame("Er is geen onderhoud met id {$maintenance->id}. list_maintenance vindt ze.", $this->callToolError($this->token, $tool, ['id' => $maintenance->id, ...($tool === 'update_maintenance' ? ['version' => $version, 'title' => 'x'] : [])]), $tool);
        }
    }

    public function test_the_version_from_a_list_is_good_for_a_change_whatever_order_the_links_came_in(): void
    {
        $maintenance = $this->planned();
        $maintenance->servers()->attach([Server::factory()->create()->id, Server::factory()->create()->id]);
        $maintenance->servers()->detach($this->server);
        $maintenance->servers()->attach($this->server);

        $listed = collect($this->callToolSuccessfully($this->token, 'list_maintenance')['maintenance'])->firstWhere('id', $maintenance->id);

        $this->callToolSuccessfully($this->token, 'update_maintenance', ['id' => $maintenance->id, 'version' => $listed['version'], 'title' => 'From the list']);
        $this->assertSame('From the list', $maintenance->refresh()->title);
    }

    public function test_the_version_covers_every_field_a_person_or_an_agent_can_change(): void
    {
        $changes = [
            'title' => 'Another title',
            'tasks' => ['inspection'],
            'status' => MaintenanceStatus::Done,
            'starts_at' => '2026-11-08 10:00:00',
            'ends_at' => '2026-11-12 23:00:00',
            'plan' => 'Another plan',
            'report' => 'Another report',
        ];

        // A new column people can change belongs in version() too, or a save could undo a change made to it meanwhile.
        $this->assertEqualsCanonicalizing([...array_keys($changes), 'user_id'], (new Maintenance)->getFillable());

        foreach ($changes as $field => $value) {
            $maintenance = $this->planned();
            $version = $maintenance->version();
            $maintenance->update([$field => $value]);

            $this->assertNotSame($version, $maintenance->fresh()->version(), $field);
        }

        $maintenance = $this->planned();
        $version = $maintenance->version();
        $maintenance->projects()->attach(Project::factory()->create());
        $this->assertNotSame($version, $maintenance->fresh()->version(), 'projects');
    }

    public function test_a_plan_an_agent_rewrote_says_so_where_agents_read_it(): void
    {
        $bob = User::factory()->create(['name' => 'Bob']);
        $this->actingAs($bob);
        $maintenance = $this->planned(['plan' => 'apt upgrade', 'user_id' => $bob->id]);
        $this->assertSame(['plan' => null, 'report' => null], $this->callToolSuccessfully($this->token, 'get_maintenance', ['id' => $maintenance->id])['texts_changed']);

        $this->update($maintenance, ['plan' => 'apt full-upgrade']);

        $read = $this->callToolSuccessfully($this->token, 'get_maintenance', ['id' => $maintenance->id]);
        $this->assertSame(['Bob', null], [$read['created_by'], $read['via']]);
        $this->assertSame(['by' => 'Ada', 'via' => 'deploy-agent', 'at' => '2026-11-09T13:00:00+01:00'], $read['texts_changed']['plan']);
        $this->assertNull($read['texts_changed']['report']);
    }

    public function test_cancelling_with_a_version_refuses_a_change_made_since(): void
    {
        $maintenance = $this->planned();
        $read = $maintenance->version();
        $maintenance->update(['starts_at' => '2026-11-20 09:00:00']);

        $this->assertStringContainsString('gewijzigd sinds je het las (nu: planned)', $this->callToolError($this->token, 'cancel_maintenance', ['id' => $maintenance->id, 'version' => $read]));
        $this->assertSame('cancelled', $this->callToolSuccessfully($this->token, 'cancel_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->version()])['maintenance']['status']);
    }

    public function test_a_title_set_to_null_is_made_from_the_work_again(): void
    {
        $maintenance = $this->planned(['title' => 'Typed by hand']);

        $answer = $this->update($maintenance, ['title' => null, 'tasks' => ['updates', 'reboot']]);

        $this->assertSame('Updates en herstart', $answer['maintenance']['title']);
    }

    /** @return array<string, array{string}> */
    public static function texts(): array
    {
        return ['plan' => ['plan'], 'report' => ['report']];
    }

    #[DataProvider('texts')]
    public function test_a_text_longer_than_an_agent_reads_is_not_overwritten_by_one(string $field): void
    {
        $maintenance = $this->planned([$field => str_repeat('a', Serializer::MAX_TEXT + 1)]);

        $this->assertSame("{$field}: Deze tekst is langer dan een agent in zijn geheel leest: wijzig hem in Herdbook zelf.", $this->callToolError($this->token, 'update_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->version(), $field => 'Short']));
        $this->update($maintenance, ['title' => 'Other']);

        $this->assertSame(Serializer::MAX_TEXT + 1, mb_strlen($maintenance->refresh()->{$field}));
    }

    public function test_cancelled_or_missing_maintenance_is_not_updated(): void
    {
        $cancelled = $this->planned(['status' => MaintenanceStatus::Cancelled]);

        $this->assertSame('Dit onderhoud is afgelast. Vraag een mens het in Herdbook terug te zetten op gepland.', $this->callToolError($this->token, 'update_maintenance', ['id' => $cancelled->id, 'version' => $cancelled->version(), 'title' => 'Again']));
        $this->assertSame('Er is geen onderhoud met id 999. list_maintenance vindt ze.', $this->callToolError($this->token, 'update_maintenance', ['id' => 999, 'version' => 'x']));
    }

    public function test_completing_takes_the_defaults_of_the_form_for_what_it_leaves_out(): void
    {
        $early = $this->planned(['starts_at' => '2026-11-12 21:00:00', 'ends_at' => '2026-11-12 23:00:00', 'report' => 'So far.']);

        $answer = $this->callToolSuccessfully($this->token, 'complete_maintenance', ['id' => $early->id]);

        $this->assertSame(['done', '2026-11-09T13:00:00+01:00', null], [$answer['maintenance']['status'], $answer['maintenance']['starts_at'], $answer['maintenance']['ends_at']]);
        $this->assertSame('So far.', $early->refresh()->report, 'Without a report, the report stays.');
    }

    public function test_completing_checks_what_will_be_stored(): void
    {
        $maintenance = $this->planned(['starts_at' => '2026-11-08 09:00:00', 'ends_at' => '2026-11-08 11:00:00']);

        $this->assertSame('starts_at: Wat al gedaan is, kan niet in de toekomst liggen.', $this->callToolError($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'starts_at' => '2026-11-10T09:00']));
        $this->assertSame('ends_at: Het einde kan niet vóór het begin liggen.', $this->callToolError($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'ends_at' => '2026-11-08T08:00']));
        $this->assertSame(MaintenanceStatus::Planned, $maintenance->refresh()->status);

        // Null: no end, where leaving it out keeps the planned end that has passed.
        $this->callToolSuccessfully($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'ends_at' => null]);
        $this->assertNull($maintenance->refresh()->ends_at);
    }

    public function test_completing_with_a_report_needs_the_version_and_a_matching_one_completes(): void
    {
        $maintenance = $this->planned(['report' => "Don't reboot db-1!"]);

        $refused = 'Een rapport vervangt het bestaande: geef de "version" die je las met get_maintenance.';
        $this->assertSame($refused, $this->callToolError($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'report' => 'All done.']));
        // Null clears the report: the same.
        $this->assertSame($refused, $this->callToolError($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'report' => null, 'version' => null]));
        $this->assertSame([MaintenanceStatus::Planned, "Don't reboot db-1!"], [$maintenance->refresh()->status, $maintenance->report]);

        $this->callToolSuccessfully($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->version(), 'report' => 'All done, db-1 left alone.']);
        $this->assertSame([MaintenanceStatus::Done, 'All done, db-1 left alone.'], [$maintenance->refresh()->status, $maintenance->report]);
    }

    public function test_completing_again_after_a_lost_answer_returns_it_and_anything_else_is_refused(): void
    {
        $maintenance = $this->planned(['starts_at' => '2026-11-08 21:00:00']);
        $call = ['id' => $maintenance->id, 'version' => $maintenance->version(), 'starts_at' => '2026-11-08T22:30', 'report' => 'All done.'];

        $first = $this->callToolSuccessfully($this->token, 'complete_maintenance', $call);
        $again = $this->callToolSuccessfully($this->token, 'complete_maintenance', $call);
        $this->assertSame([$first['maintenance'], null, true], [$again['maintenance'], $first['unchanged'] ?? null, $again['unchanged']]);

        $this->assertSame('Dit onderhoud is al gedaan. Wijzig het met update_maintenance.', $this->callToolError($this->token, 'complete_maintenance', [...$call, 'report' => 'Something else.']));
        $this->assertSame('All done.', $maintenance->refresh()->report);
    }

    public function test_completing_refuses_a_long_report_and_a_version_that_changed(): void
    {
        $long = $this->planned(['report' => str_repeat('a', Serializer::MAX_TEXT + 1)]);
        $this->assertSame('report: Deze tekst is langer dan een agent in zijn geheel leest: wijzig hem in Herdbook zelf.', $this->callToolError($this->token, 'complete_maintenance', ['id' => $long->id, 'version' => $long->version(), 'report' => 'Short']));

        $changed = $this->planned();
        $read = $changed->version();
        $changed->update(['plan' => 'Changed by a person']);
        $this->assertStringContainsString('gewijzigd sinds je het las', $this->callToolError($this->token, 'complete_maintenance', ['id' => $changed->id, 'version' => $read]));
        $this->assertSame([MaintenanceStatus::Planned, MaintenanceStatus::Planned], [$long->refresh()->status, $changed->refresh()->status]);
    }

    public function test_completing_again_returns_it_and_anything_else_says_where_to_go(): void
    {
        $maintenance = $this->planned(['starts_at' => '2026-11-08 09:00:00']);
        $first = $this->callToolSuccessfully($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->version(), 'report' => 'Done.']);

        $this->assertSame($first['maintenance'], $this->callToolSuccessfully($this->token, 'complete_maintenance', ['id' => $maintenance->id])['maintenance']);
        $this->assertSame('Dit onderhoud is al gedaan. Wijzig het met update_maintenance.', $this->callToolError($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'version' => $first['maintenance']['version'], 'report' => 'Again.']));
        $this->assertSame('Dit onderhoud is afgelast. Leg het werk vast met log_maintenance, of vraag een mens het in Herdbook terug te zetten op gepland.', $this->callToolError($this->token, 'complete_maintenance', ['id' => $this->planned(['status' => MaintenanceStatus::Cancelled])->id]));
    }

    public function test_true_is_no_id(): void
    {
        // Id 1 by name: MySQL doesn't start counting again for every test.
        $maintenance = $this->planned(['id' => 1]);

        foreach (['get_maintenance', 'update_maintenance', 'complete_maintenance', 'cancel_maintenance'] as $tool) {
            $this->assertSame('Het veld id moet een getal zijn.', $this->callToolError($this->token, $tool, ['id' => true, ...($tool === 'update_maintenance' ? ['version' => $maintenance->version(), 'title' => 'x'] : [])]), $tool);
        }

        $this->assertSame(MaintenanceStatus::Planned, $maintenance->refresh()->status);
    }

    public function test_cancelling_twice_is_once_and_done_work_stays_done(): void
    {
        $maintenance = $this->planned();

        $first = $this->callToolSuccessfully($this->token, 'cancel_maintenance', ['id' => $maintenance->id]);
        $again = $this->callToolSuccessfully($this->token, 'cancel_maintenance', ['id' => $maintenance->id]);
        $this->assertSame(['cancelled', null, 'cancelled', true], [$first['maintenance']['status'], $first['unchanged'] ?? null, $again['maintenance']['status'], $again['unchanged']]);
        $this->assertSame(1, Activity::query()->where('subject_type', 'maintenance')->where('subject_id', $maintenance->id)->where('event', 'updated')->count());

        $done = Maintenance::factory()->create();
        $this->assertSame('Dit onderhoud is gedaan en kan niet worden afgelast.', $this->callToolError($this->token, 'cancel_maintenance', ['id' => $done->id]));
    }

    public function test_the_activity_log_names_the_agent(): void
    {
        $answer = $this->callToolSuccessfully($this->token, 'schedule_maintenance', ['tasks' => ['updates'], 'starts_at' => '2026-11-12T22:00', 'plan' => 'Steps.', 'server_ids' => [$this->server->id]]);
        $maintenance = Maintenance::query()->findOrFail($answer['maintenance']['id']);
        $this->update($maintenance, ['plan' => 'Other steps.']);

        $created = Activity::query()->where('subject_type', 'maintenance')->where('subject_id', $maintenance->id)->where('event', 'created')->sole();
        $updated = Activity::query()->where('subject_type', 'maintenance')->where('subject_id', $maintenance->id)->where('event', 'updated')->sole();
        foreach ([$created, $updated] as $activity) {
            $this->assertTrue($activity->causer->is($this->user));
            $this->assertSame(['deploy-agent', $this->agent->id], [$activity->properties['via'], $activity->properties['agent_token_id']]);
            $this->assertSame('Ada via deploy-agent', ActivityPresenter::causer($activity));
        }
        $this->assertSame(['plan'], $updated->properties['texts']);

        $this->actingAs($this->user);
        Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $maintenance->fresh(), 'pageClass' => ViewMaintenance::class])
            ->assertSee('Ada via deploy-agent');
    }

    public function test_an_agent_keeps_the_links_a_person_wrote_but_adds_none(): void
    {
        $person = '1. Read [the changelog](https://elsewhere.example/changelog).';
        $maintenance = $this->planned(['plan' => $person]);

        $this->update($maintenance, ['plan' => $person."\n2. Upgrade."]);
        $this->assertSame($person."\n2. Upgrade.", $maintenance->refresh()->plan);

        $this->assertSame('plan: Geen links naar andere sites: zet adressen tussen backticks.', $this->callToolError($this->token, 'update_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->version(), 'plan' => $maintenance->plan."\n3. See [this](https://elsewhere.example/other)."]));
        $this->assertSame('report: Geen links naar andere sites: zet adressen tussen backticks.', $this->callToolError($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->version(), 'report' => 'Done, see www.elsewhere.example.']));
        $this->assertSame([MaintenanceStatus::Planned, $person."\n2. Upgrade."], [$maintenance->refresh()->status, $maintenance->plan]);

        // Into Herdbook itself: fine.
        $this->update($maintenance, ['plan' => $maintenance->plan."\n3. See [the server](".config('app.url').'/servers/1).']);
        $this->assertStringContainsString('3. See [the server]', $maintenance->refresh()->plan);
    }

    public function test_the_activity_log_keeps_the_text_an_agent_replaced_to_put_it_back(): void
    {
        $bob = 'The plan Bob wrote: <b>x</b>, [the docs](https://elsewhere.example).';
        $maintenance = $this->planned(['plan' => $bob]);
        $this->update($maintenance, ['plan' => 'Something else entirely.']);
        $this->update($maintenance, ['report' => 'A first report.']);
        $this->update($maintenance, ['report' => null]);
        $this->actingAs($this->user);
        $maintenance->refresh()->update(['plan' => 'By hand.']);

        [$replaced, $added, $cleared, $byHand] = Activity::query()->where('subject_type', 'maintenance')->where('subject_id', $maintenance->id)->where('event', 'updated')->orderBy('id')->get()->all();
        $this->assertSame(['plan' => $bob], $replaced->properties['previous_texts']);
        $this->assertSame(['report' => 'A first report.'], $cleared->properties['previous_texts']);
        // Nothing replaced, or not by an agent: nothing kept.
        $this->assertArrayNotHasKey('previous_texts', $added->properties->all());
        $this->assertArrayNotHasKey('previous_texts', $byHand->properties->all());

        Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $maintenance->fresh(), 'pageClass' => ViewMaintenance::class])
            ->assertActionHidden(TestAction::make('previousTexts')->table($added))
            ->assertActionHidden(TestAction::make('previousTexts')->table($byHand))
            ->mountAction(TestAction::make('previousTexts')->table($replaced))
            ->assertMountedActionModalSee(['Wat de agent verving.', 'Plan'])
            // As written: escaped, not rendered as markdown.
            ->assertMountedActionModalSeeHtml('<span class="hb-as-written">The plan Bob wrote: &lt;b&gt;x&lt;/b&gt;, [the docs](https://elsewhere.example).</span>')
            ->assertMountedActionModalDontSeeHtml('<a href="https://elsewhere.example');
    }

    public function test_only_a_model_that_opts_in_keeps_the_texts_an_agent_replaced(): void
    {
        $this->actingAs($this->user->withAgentToken($this->agent));
        $incident = Incident::factory()->create(['body' => 'The report Bob wrote.']);

        $incident->update(['body' => 'Rewritten.']);

        $activity = Activity::query()->where('subject_type', 'incident')->where('subject_id', $incident->id)->where('event', 'updated')->sole();
        $this->assertSame(['body'], $activity->properties['texts']);
        $this->assertArrayNotHasKey('previous_texts', $activity->properties->all());
    }

    public function test_a_change_by_hand_names_no_agent(): void
    {
        $this->actingAs($this->user);
        $maintenance = $this->planned();

        $maintenance->update(['title' => 'By hand']);

        $activity = Activity::query()->where('subject_type', 'maintenance')->where('subject_id', $maintenance->id)->where('event', 'updated')->sole();
        $this->assertArrayNotHasKey('via', $activity->properties->all());
        $this->assertSame('Ada', ActivityPresenter::causer($activity));
    }

    public function test_an_edit_form_left_open_does_not_undo_what_an_agent_did(): void
    {
        $maintenance = $this->planned(['starts_at' => '2026-11-08 09:00:00']);
        $this->actingAs(User::factory()->withAppAuthentication()->create());
        $form = Livewire::test(EditMaintenance::class, ['record' => $maintenance->getRouteKey()]);

        // In the same second: a timestamp would not tell.
        $this->callToolSuccessfully($this->token, 'complete_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->version(), 'report' => 'By the agent.']);

        $form->fillForm(['title' => 'By hand'])
            ->call('save')
            ->assertNotified('Dit onderhoud is intussen gewijzigd.');

        $maintenance->refresh();
        $this->assertSame([MaintenanceStatus::Done, 'By the agent.'], [$maintenance->status, $maintenance->report]);
        $this->assertNotSame('By hand', $maintenance->title);
    }

    public function test_an_edit_form_left_open_does_not_undo_links_an_agent_changed(): void
    {
        $maintenance = $this->planned();
        $other = Server::factory()->create();
        $this->actingAs($this->user);
        $form = Livewire::test(EditMaintenance::class, ['record' => $maintenance->getRouteKey()]);

        $this->update($maintenance, ['server_ids' => [$other->id]]);

        $form->call('save')->assertNotified('Dit onderhoud is intussen gewijzigd.');
        $this->assertSame([$other->id], $maintenance->servers()->pluck('servers.id')->all());
    }

    public function test_an_edit_form_left_open_does_not_undo_a_cancel(): void
    {
        $maintenance = $this->planned();
        $this->actingAs($this->user);
        $form = Livewire::test(EditMaintenance::class, ['record' => $maintenance->getRouteKey()]);

        $this->callToolSuccessfully($this->token, 'cancel_maintenance', ['id' => $maintenance->id]);

        $form->fillForm(['title' => 'By hand'])->call('save')->assertNotified('Dit onderhoud is intussen gewijzigd.');
        $this->assertSame(MaintenanceStatus::Cancelled, $maintenance->refresh()->status);
    }

    public function test_an_edit_form_left_open_does_not_undo_a_new_end(): void
    {
        $maintenance = $this->planned();
        $this->actingAs($this->user);
        $form = Livewire::test(EditMaintenance::class, ['record' => $maintenance->getRouteKey()]);

        $this->update($maintenance, ['ends_at' => '2026-11-12T23:30']);

        $form->fillForm(['title' => 'By hand'])->call('save')->assertNotified('Dit onderhoud is intussen gewijzigd.');
        $this->assertNotNull($maintenance->refresh()->ends_at);
    }

    public function test_an_edit_form_saves_twice_in_a_row(): void
    {
        $maintenance = $this->planned();
        $this->actingAs($this->user);

        $form = Livewire::test(EditMaintenance::class, ['record' => $maintenance->getRouteKey()])
            ->fillForm(['title' => 'First'])
            ->call('save')
            ->assertNotNotified('Dit onderhoud is intussen gewijzigd.');
        $form->fillForm(['title' => 'Second'])->call('save')->assertNotNotified('Dit onderhoud is intussen gewijzigd.');

        $this->assertSame('Second', $maintenance->refresh()->title);
    }

    public function test_the_complete_modal_does_not_save_over_a_report_an_agent_wrote_since_it_opened(): void
    {
        $maintenance = $this->planned(['starts_at' => '2026-11-08 09:00:00']);
        $this->actingAs($this->user);
        $page = Livewire::test(ViewMaintenance::class, ['record' => $maintenance->getRouteKey()])->mountAction('complete');

        $this->update($maintenance, ['report' => 'Written by the agent.']);

        // The modal stays open, with what was typed in it.
        $page->fillForm(['report' => 'Typed by hand.'])
            ->callMountedAction()
            ->assertNotified('Dit onderhoud is intussen gewijzigd.')
            ->assertActionMounted('complete')
            ->assertSchemaStateSet(['report' => 'Typed by hand.']);
        $this->assertSame([MaintenanceStatus::Planned, 'Written by the agent.'], [$maintenance->refresh()->status, $maintenance->report]);
    }

    public function test_every_tool_named_in_a_description_or_the_instructions_exists(): void
    {
        $initialize = $this->mcp($this->token);
        $tools = collect($this->mcp($this->token, 'tools/list')->json('result.tools'));
        $names = $tools->pluck('name')->all();
        $texts = $tools->pluck('description')->push($initialize->json('result.instructions'))->implode(' ');

        preg_match_all('/\b[a-z]+_(?:maintenance|inventory)\b/', $texts, $mentioned);

        $this->assertNotEmpty($mentioned[0]);
        $this->assertSame([], array_values(array_diff(array_unique($mentioned[0]), $names)));
    }

    public function test_the_change_tools_say_what_they_do_to_existing_work(): void
    {
        $tools = collect($this->mcp($this->token, 'tools/list')->json('result.tools'))->keyBy('name');
        $hints = fn (string $tool): array => array_intersect_key($tools[$tool]['annotations'], ['readOnlyHint' => 0, 'destructiveHint' => 0, 'idempotentHint' => 0, 'openWorldHint' => 0]);

        foreach (['update_maintenance', 'complete_maintenance', 'cancel_maintenance'] as $tool) {
            $this->assertSame(['destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], $hints($tool), $tool);
        }
        $this->assertEqualsCanonicalizing(['id', 'version'], $tools['update_maintenance']['inputSchema']['required']);
        $this->assertSame(['string', 'null'], $tools['update_maintenance']['inputSchema']['properties']['ends_at']['type']);
    }

    public function test_a_read_token_sees_only_the_reading_tools_and_changes_nothing(): void
    {
        [, $reader] = $this->agentToken(access: AgentAccess::Read);
        $maintenance = $this->planned();

        $this->assertEqualsCanonicalizing(['search_inventory', 'list_maintenance', 'get_maintenance'], array_column($this->mcp($reader, 'tools/list')->json('result.tools'), 'name'));

        foreach (['update_maintenance', 'complete_maintenance', 'cancel_maintenance'] as $tool) {
            $this->callTool($reader, $tool, ['id' => $maintenance->id])->assertJsonPath('error.code', -32602);
        }

        $this->assertSame(MaintenanceStatus::Planned, $maintenance->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function update(Maintenance $maintenance, array $changes): array
    {
        return $this->callToolSuccessfully($this->token, 'update_maintenance', ['id' => $maintenance->id, 'version' => $maintenance->fresh()->version(), ...$changes]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function planned(array $attributes = []): Maintenance
    {
        $maintenance = Maintenance::factory()->planned()->create(['tasks' => ['updates'], 'starts_at' => '2026-11-12 21:00:00', ...$attributes]);
        $maintenance->servers()->attach($this->server);

        return $maintenance;
    }

    /**
     * Someone else changes the row right after the tool loaded it, before
     * it locks it.
     *
     * @param  array<string, mixed>  $changes
     */
    private function changeWhenLoaded(Maintenance $maintenance, array $changes): void
    {
        $done = false;
        Maintenance::retrieved(function (Maintenance $loaded) use ($maintenance, $changes, &$done): void {
            if (! $done && $loaded->is($maintenance)) {
                $done = true;
                DB::table('maintenances')->where('id', $maintenance->id)->update($changes);
            }
        });
    }

    private function deleteWhenLoaded(Maintenance $maintenance): void
    {
        $done = false;
        Maintenance::retrieved(function (Maintenance $loaded) use ($maintenance, &$done): void {
            if (! $done && $loaded->is($maintenance)) {
                $done = true;
                DB::table('maintainables')->where('maintenance_id', $maintenance->id)->delete();
                DB::table('maintenances')->where('id', $maintenance->id)->delete();
            }
        });
    }
}
