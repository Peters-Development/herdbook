<?php

namespace Tests\Feature\Filament;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceTask;
use App\Filament\RelationManagers\MaintenancesRelationManager;
use App\Filament\Resources\Incidents\Pages\ViewIncident;
use App\Filament\Resources\Incidents\RelationManagers\TimelineEntriesRelationManager;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Maintenances\Pages\CreateMaintenance;
use App\Filament\Resources\Maintenances\Pages\ListMaintenances;
use App\Filament\Resources\Maintenances\Pages\ViewMaintenance;
use App\Filament\Resources\Maintenances\Schemas\MaintenanceForm;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Support\ActivityPresenter;
use App\Filament\Support\LinkSchema;
use App\Filament\Support\MaintenanceSchema;
use App\Filament\Widgets\PlannedMaintenance;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Project;
use App\Models\Server;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class MaintenanceFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-24 12:00:00');
    }

    public function test_a_quick_entry_on_a_server_is_done_now_by_whoever_wrote_it_and_named_after_its_work(): void
    {
        $user = $this->signIn();
        $server = Server::factory()->create();

        Livewire::test(MaintenancesRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class])
            ->callAction(TestAction::make('log')->table(), ['tasks' => ['updates', 'reboot'], 'report' => 'apt upgrade, 12 pakketten.'])
            ->assertHasNoFormErrors();

        $maintenance = Maintenance::query()->sole();
        $this->assertSame(MaintenanceStatus::Done, $maintenance->status);
        $this->assertSame('Updates en herstart', $maintenance->title);
        $this->assertSame('2026-10-24 12:00', $maintenance->starts_at->format('Y-m-d H:i'));
        $this->assertTrue($maintenance->user->is($user));
        $this->assertTrue($server->maintenances()->sole()->is($maintenance));
    }

    public function test_a_quick_entry_needs_its_work_and_refuses_a_private_key(): void
    {
        $this->signIn();
        $parameters = ['ownerRecord' => Server::factory()->create(), 'pageClass' => ViewServer::class];

        Livewire::test(MaintenancesRelationManager::class, $parameters)
            ->callAction(TestAction::make('log')->table(), ['tasks' => []])
            ->assertHasFormErrors(['tasks']);

        Livewire::test(MaintenancesRelationManager::class, $parameters)
            ->callAction(TestAction::make('log')->table(), ['tasks' => ['other'], 'report' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc"])
            ->assertHasFormErrors(['report']);

        $this->assertSame(0, Maintenance::query()->count());
    }

    public function test_the_title_says_what_was_done(): void
    {
        $this->assertSame('Updates, herstart en controle', MaintenanceTask::describe(['inspection', 'updates', 'reboot']));
        $this->assertSame('Upgrade', MaintenanceTask::describe(['upgrade', 'other']));
        $this->assertSame('Onderhoud', MaintenanceTask::describe(['other']));
        $this->assertSame('Ubuntu 24.04', Maintenance::factory()->create(['title' => 'Ubuntu 24.04'])->title);
        $this->assertSame('Updates, reboot and inspection', MaintenanceTask::describe(['inspection', 'updates', 'reboot'], 'en'));
    }

    public function test_a_generated_title_follows_the_work_in_another_language_than_it_was_made_in(): void
    {
        $maintenance = Maintenance::factory()->create(['title' => null, 'tasks' => ['updates', 'reboot']]);
        $this->assertSame('Updates en herstart', $maintenance->title);

        app()->setLocale('en');
        $maintenance->update(['tasks' => ['updates']]);

        $this->assertSame('Updates', $maintenance->fresh()->title);
        $maintenance->update(['tasks' => ['updates', 'upgrade']]);
        $this->assertSame('Updates and upgrade', $maintenance->fresh()->title);
    }

    public function test_planning_from_a_project_opens_the_form_with_the_project_and_planned(): void
    {
        $this->signIn();
        $project = Project::factory()->create();

        Livewire::test(MaintenancesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
            ->assertActionHasUrl(TestAction::make('create')->table(), MaintenanceResource::getUrl('create', [
                LinkSchema::QUERY_PARAMETER => LinkSchema::linkKey($project),
                MaintenanceForm::STATUS_PARAMETER => 'planned',
            ]));

        Livewire::withQueryParams([LinkSchema::QUERY_PARAMETER => LinkSchema::linkKey($project), MaintenanceForm::STATUS_PARAMETER => 'planned'])
            ->test(CreateMaintenance::class)
            ->assertSee('Onderhoud plannen')
            // No time yet: a default of "now" would be overdue a minute later.
            ->assertSchemaStateSet(['projects' => [$project->id], 'status' => MaintenanceStatus::Planned, 'starts_at' => null])
            ->assertFormFieldDoesNotExist('domains')
            ->assertFormFieldDoesNotExist('clients')
            ->fillForm([
                'tasks' => ['upgrade'],
                'title' => 'Upgrade naar PHP 8.5',
                'starts_at' => '2026-11-07 22:00',
                'ends_at' => '2026-11-07 21:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at'])
            ->fillForm(['ends_at' => '2026-11-08 01:00', 'plan' => "1. Snapshot\n2. Upgrade"])
            ->call('create')
            ->assertHasNoFormErrors();

        $maintenance = $project->maintenances()->sole();
        $this->assertSame([MaintenanceStatus::Planned, 'Upgrade naar PHP 8.5'], [$maintenance->status, $maintenance->title]);
        // Typed in Amsterdam time, stored in UTC.
        $this->assertSame('2026-11-07 21:00', $maintenance->starts_at->utc()->format('Y-m-d H:i'));
    }

    public function test_a_planned_maintenance_past_its_time_is_overdue_until_it_is_completed(): void
    {
        $this->signIn();
        $maintenance = Maintenance::factory()->planned()->create(['starts_at' => '2026-10-22 20:00:00', 'ends_at' => '2026-10-22 22:00:00']);
        $window = Maintenance::factory()->planned()->create(['starts_at' => '2026-10-24 11:00:00', 'ends_at' => '2026-10-24 14:00:00']);

        $this->assertTrue($maintenance->isOverdue());
        $this->assertFalse($window->isOverdue(), 'Still inside its window.');

        Livewire::test(ViewMaintenance::class, ['record' => $maintenance->getRouteKey()])
            ->assertSee('Over tijd')
            ->callAction('complete', ['report' => 'Gedaan, twee dagen later dan gepland.'])
            ->assertHasNoFormErrors();

        $maintenance->refresh();
        $this->assertSame(MaintenanceStatus::Done, $maintenance->status);
        $this->assertFalse($maintenance->isOverdue());
        $this->assertSame(['2026-10-22 20:00', 'Gedaan, twee dagen later dan gepland.'], [$maintenance->starts_at->format('Y-m-d H:i'), $maintenance->report]);
    }

    public function test_completing_before_the_planned_time_starts_it_now(): void
    {
        $this->signIn();
        $maintenance = Maintenance::factory()->planned()->create(['starts_at' => '2026-10-31 22:00:00', 'ends_at' => '2026-11-01 01:00:00']);

        Livewire::test(ListMaintenances::class)
            ->callAction(TestAction::make('complete')->table($maintenance))
            ->assertHasNoFormErrors();

        $maintenance->refresh();
        $this->assertSame(MaintenanceStatus::Done, $maintenance->status);
        $this->assertSame('2026-10-24 12:00', $maintenance->starts_at->format('Y-m-d H:i'));
        $this->assertNull($maintenance->ends_at, 'A planned end is not when it was done.');

        Livewire::test(ListMaintenances::class)->assertActionHidden(TestAction::make('complete')->table($maintenance));
    }

    public function test_the_server_page_says_when_each_task_was_last_done_and_what_is_planned(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        $this->done($server, ['updates', 'reboot'], '2026-10-21 09:00:00');
        $this->done($server, ['updates'], '2026-09-01 09:00:00');
        $this->done(Server::factory()->create(), ['inspection'], '2026-10-23 09:00:00');
        $server->maintenances()->attach(Maintenance::factory()->create(['tasks' => ['inspection'], 'status' => MaintenanceStatus::Cancelled]));
        $planned = Maintenance::factory()->planned()->create(['title' => 'Upgrade naar 24.04', 'tasks' => ['upgrade'], 'starts_at' => '2026-11-07 21:00:00']);
        $server->maintenances()->attach($planned);

        $lastDone = $server->lastDone();
        $this->assertSame('2026-10-21', $lastDone['updates']?->toDateString());
        $this->assertSame('2026-10-21', $lastDone['reboot']?->toDateString());
        $this->assertNull($lastDone['inspection'], 'Another server, or called off: not done here.');
        $this->assertNull($lastDone['upgrade'], 'Planned is not done.');

        $this->get(ServerResource::getUrl('view', ['record' => $server]))
            ->assertOk()
            ->assertSeeInOrder(['Onderhoud', 'Updates', '21-10-2026', 'Herstart', '21-10-2026', 'Controle', 'Nog niet', 'Gepland', '07-11-2026 22:00', 'Upgrade naar 24.04']);
    }

    public function test_the_server_list_shows_and_sorts_by_the_last_updates(): void
    {
        $this->signIn();
        $recent = Server::factory()->create(['name' => 'recent']);
        $old = Server::factory()->create(['name' => 'old']);
        $never = Server::factory()->create(['name' => 'nooit']);
        $this->done($recent, ['updates'], '2026-10-20 09:00:00');
        $this->done($old, ['updates'], '2026-08-01 09:00:00');
        $this->done($never, ['reboot'], '2026-10-23 09:00:00');

        Livewire::test(ListServers::class)
            ->assertTableColumnFormattedStateSet('last_updates_at', '20-10-2026', $recent)
            ->assertTableColumnStateSet('last_updates_at', null, $never)
            ->sortTable('last_updates_at', 'desc')
            ->assertCanSeeTableRecords([$recent, $old], inOrder: true);
    }

    public function test_the_dashboard_lists_planned_maintenance_the_overdue_first_and_only_when_there_is_some(): void
    {
        $this->signIn();
        $this->assertFalse(PlannedMaintenance::canView());

        Maintenance::factory()->planned()->create(['title' => 'Volgende week', 'starts_at' => '2026-10-31 20:00:00']);
        // Started earlier, but its window is still open: after the one that is overdue.
        Maintenance::factory()->planned()->create(['title' => 'Lang venster', 'starts_at' => '2026-10-19 20:00:00', 'ends_at' => '2026-10-26 20:00:00']);
        Maintenance::factory()->planned()->create(['title' => 'Vergeten', 'starts_at' => '2026-10-20 20:00:00']);
        Maintenance::factory()->create(['title' => 'Al gedaan']);

        $this->assertTrue(PlannedMaintenance::canView());
        Livewire::test(PlannedMaintenance::class)
            ->assertSeeInOrder(['Vergeten', 'Over tijd', 'Lang venster', 'Gepland', 'Volgende week', 'Gepland'])
            ->assertDontSee('Al gedaan');
    }

    public function test_deleting_a_server_unlinks_its_maintenance_and_keeps_it(): void
    {
        $server = Server::factory()->create();
        $maintenance = $this->done($server, ['updates'], '2026-10-20 09:00:00');

        $server->delete();

        $this->assertTrue($maintenance->exists());
        $this->assertSame(0, $maintenance->servers()->count());
    }

    public function test_the_activity_log_names_the_work_and_leaves_the_texts_out(): void
    {
        $maintenance = Maintenance::factory()->create(['tasks' => ['updates']]);
        $maintenance->update(['tasks' => ['updates', 'reboot'], 'report' => 'Lang verslag']);

        $activity = Activity::query()->where('event', 'updated')->sole();

        $this->assertSame("Titel: Updates → Updates en herstart\nWerk: Updates → Updates, Herstart", ActivityPresenter::changes($activity));
    }

    public function test_the_report_renders_markdown_without_running_scripts(): void
    {
        $this->signIn();
        $maintenance = Maintenance::factory()->create(['report' => "## Gedaan\n\n<script>alert('x')</script>"]);

        $this->get(MaintenanceResource::getUrl('view', ['record' => $maintenance]))
            ->assertOk()
            ->assertSee('Gedaan')
            ->assertDontSee("<script>alert('x')</script>", false);
    }

    public function test_work_that_is_done_cannot_lie_in_the_future_planned_work_can(): void
    {
        $this->signIn();
        $server = Server::factory()->create();

        Livewire::test(MaintenancesRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class])
            ->callAction(TestAction::make('log')->table(), ['tasks' => ['updates'], 'starts_at' => '2026-12-01 10:00'])
            ->assertHasFormErrors(['starts_at']);

        Livewire::test(CreateMaintenance::class)
            ->fillForm(['tasks' => ['updates'], 'status' => MaintenanceStatus::Done, 'starts_at' => '2026-12-01 10:00'])
            ->call('create')
            ->assertHasFormErrors(['starts_at'])
            ->fillForm(['status' => MaintenanceStatus::Planned])
            ->call('create')
            ->assertHasNoFormErrors();

        $planned = Maintenance::query()->sole();
        Livewire::test(ViewMaintenance::class, ['record' => $planned->getRouteKey()])
            ->callAction('complete', ['starts_at' => '2026-12-01 10:00'])
            ->assertHasFormErrors(['starts_at']);
        $this->assertSame(MaintenanceStatus::Planned, $planned->fresh()->status);
    }

    public function test_a_title_made_from_the_work_follows_the_work_a_typed_one_stays(): void
    {
        $quick = Maintenance::factory()->create(['tasks' => ['updates']]);
        $typed = Maintenance::factory()->create(['tasks' => ['updates'], 'title' => 'apt upgrade']);

        $quick->update(['tasks' => ['updates', 'reboot']]);
        $typed->update(['tasks' => ['updates', 'reboot']]);

        $this->assertSame(['Updates en herstart', 'apt upgrade'], [$quick->title, $typed->title]);
    }

    public function test_called_off_is_not_overdue_and_cannot_be_completed(): void
    {
        $this->signIn();
        $maintenance = Maintenance::factory()->planned()->create(['starts_at' => '2026-10-20 20:00:00']);

        Livewire::test(ListMaintenances::class)
            ->assertSee('Over tijd')
            ->callAction(TestAction::make('cancel')->table($maintenance))
            ->assertActionHidden(TestAction::make('complete')->table($maintenance))
            ->assertActionHidden(TestAction::make('cancel')->table($maintenance));

        $maintenance->refresh();
        $this->assertSame(MaintenanceStatus::Cancelled, $maintenance->status);
        $this->assertFalse($maintenance->isOverdue());
        $this->assertSame(0, Maintenance::query()->overdue()->count());
    }

    public function test_overdue_has_a_filter_and_a_count_in_the_menu(): void
    {
        $this->signIn();
        $late = Maintenance::factory()->planned()->create(['starts_at' => '2026-10-20 20:00:00']);
        $inWindow = Maintenance::factory()->planned()->create(['starts_at' => '2026-10-24 11:00:00', 'ends_at' => '2026-10-24 14:00:00']);
        $soon = Maintenance::factory()->planned()->create(['starts_at' => '2026-10-30 20:00:00']);

        $this->assertSame('1', MaintenanceResource::getNavigationBadge());
        Livewire::test(ListMaintenances::class)
            ->filterTable('overdue')
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$inWindow, $soon]);
    }

    public function test_the_server_page_says_when_planned_work_is_overdue(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        $server->maintenances()->attach(Maintenance::factory()->planned()->create(['title' => 'Kernel', 'starts_at' => '2026-10-22 20:00:00']));

        $this->get(ServerResource::getUrl('view', ['record' => $server]))->assertSee('Over tijd · Kernel');
    }

    public function test_a_quick_entry_on_a_project_links_the_project(): void
    {
        $this->signIn();
        $project = Project::factory()->create();

        Livewire::test(MaintenancesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
            ->callAction(TestAction::make('log')->table(), ['tasks' => ['inspection']])
            ->assertHasNoFormErrors()
            ->assertNotified('Onderhoud vastgelegd');

        $this->assertSame('Controle', $project->maintenances()->sole()->title);
    }

    public function test_the_server_list_shows_the_day_in_amsterdam(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        // 01:30 on the 21st in Amsterdam.
        $this->done($server, ['updates'], '2026-10-20 23:30:00');

        Livewire::test(ListServers::class)->assertTableColumnFormattedStateSet('last_updates_at', '21-10-2026', $server);
    }

    public function test_search_tells_entries_with_the_same_title_apart(): void
    {
        $this->signIn();
        $maintenance = $this->done(Server::factory()->create(['name' => 'herd-app']), ['updates'], '2026-10-20 09:00:00');

        $result = MaintenanceResource::getGlobalSearchResults('Updates')->first();

        $this->assertSame(['Wanneer' => '20-10-2026', 'Bij' => 'herd-app'], $result->details);
        $this->assertSame('Updates · 20-10-2026', MaintenanceSchema::titleWithDate($maintenance));
    }

    public function test_a_status_that_is_not_text_in_the_address_is_ignored(): void
    {
        $this->signIn();

        $this->get(MaintenanceResource::getUrl('create').'?status[]=planned&link[]=server:1')->assertOk();
    }

    public function test_there_is_no_create_another_that_would_shift_the_time(): void
    {
        $this->signIn();
        $incident = Incident::factory()->create();

        $this->assertFalse(Livewire::test(CreateMaintenance::class)->instance()->canCreateAnother());
        $create = collect(Livewire::test(TimelineEntriesRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->instance()->getTable()->getHeaderActions())->first(fn ($action): bool => $action->getName() === 'create');
        $this->assertFalse($create->canCreateAnother());
    }

    public function test_the_heading_says_planning_while_the_status_is_planned(): void
    {
        $this->signIn();

        Livewire::test(CreateMaintenance::class)
            ->assertDontSee('Onderhoud plannen')
            ->fillForm(['status' => MaintenanceStatus::Planned])
            ->assertSee('Onderhoud plannen');
    }

    public function test_done_work_is_never_stored_in_the_future(): void
    {
        $maintenance = Maintenance::factory()->create(['starts_at' => '2026-10-24 13:00:00']);
        $planned = Maintenance::factory()->planned()->create(['starts_at' => '2026-10-24 13:00:00']);

        $this->assertSame('2026-10-24 12:00', $maintenance->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-24 13:00', $planned->starts_at->format('Y-m-d H:i'), 'Planned work may.');
    }

    public function test_the_server_page_shows_overdue_work_before_an_open_window(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        $server->maintenances()->attach(Maintenance::factory()->planned()->create(['title' => 'Lang venster', 'starts_at' => '2026-10-19 20:00:00', 'ends_at' => '2026-10-26 20:00:00']));
        $server->maintenances()->attach(Maintenance::factory()->planned()->create(['title' => 'Vergeten', 'starts_at' => '2026-10-20 20:00:00']));

        $this->assertSame('Vergeten', $server->nextMaintenance()->title);
    }

    public function test_the_server_page_updates_after_something_changes_on_its_tab(): void
    {
        $this->signIn();
        $server = Server::factory()->create();
        $page = Livewire::test(ViewServer::class, ['record' => $server->getRouteKey()])->assertDontSee('24-10-2026');

        Livewire::test(MaintenancesRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class])
            ->callAction(TestAction::make('log')->table(), ['tasks' => ['updates']])
            ->assertDispatched(MaintenancesRelationManager::CHANGED);

        $page->dispatch(MaintenancesRelationManager::CHANGED)->assertSee('24-10-2026');
    }

    /**
     * @param  list<string>  $tasks
     */
    private function done(Server $server, array $tasks, string $at): Maintenance
    {
        $maintenance = Maintenance::factory()->create(['tasks' => $tasks, 'starts_at' => $at]);
        $server->maintenances()->attach($maintenance);

        return $maintenance;
    }
}
