<?php

namespace Tests\Feature\Filament;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Filament\Resources\Incidents\Pages\EditIncident;
use App\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Incidents\Pages\ViewIncident;
use App\Filament\Resources\Incidents\RelationManagers\ActionsRelationManager;
use App\Filament\Resources\Incidents\RelationManagers\TimelineEntriesRelationManager;
use App\Filament\Widgets\OpenIncidentActions;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use PDOException;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class IncidentChangesTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-09 12:00:00');
    }

    public function test_the_version_covers_every_field_and_link_a_person_or_an_agent_can_change(): void
    {
        $changes = [
            'title' => 'Another title',
            'severity' => IncidentSeverity::Sev1,
            'status' => IncidentStatus::Resolved,
            'occurred_on' => '2026-10-01',
            'started_at' => '2026-10-01 10:00:00',
            'detected_at' => '2026-10-01 10:05:00',
            'resolved_at' => '2026-10-01 11:00:00',
            'impact' => 'Another impact',
            'root_cause' => 'Another cause',
            'body' => 'Another report',
        ];

        // A new column people can change belongs in the version too, or a save could undo a change made to it meanwhile.
        $this->assertEqualsCanonicalizing(array_keys($changes), Incident::versionedAttributes());
        $this->assertEqualsCanonicalizing([...Incident::versionedAttributes(), 'source', 'imported_from', 'user_id'], (new Incident)->getFillable());

        // Stable: the same in memory, read plainly and read under the lock.
        $incident = $this->incident();
        $this->assertSame([$incident->version(), $incident->version()], [$incident->fresh()->version(), Incident::locked($incident->id)->version()]);

        foreach ($changes as $field => $value) {
            $incident = $this->incident();
            $version = $incident->version();
            $incident->update([$field => $value]);

            $this->assertNotSame($version, $incident->fresh()->version(), $field);
        }

        $this->assertSame(['projects', 'servers', 'domains', 'networks', 'clients'], Incident::versionedRelations());

        foreach ([Project::class, Server::class, Domain::class, Network::class, Client::class] as $model) {
            $incident = $this->incident();
            $version = $incident->version();
            $incident->{str((new $model)->getTable())->camel()->toString()}()->attach($model::factory()->create());

            $this->assertNotSame($version, $incident->fresh()->version(), $model);
        }
    }

    public function test_an_edit_form_left_open_does_not_undo_what_monitoring_or_an_agent_did_meanwhile(): void
    {
        $this->signIn();
        $incident = $this->incident();

        $form = Livewire::test(EditIncident::class, ['record' => $incident->getRouteKey()]);

        // As monitoring does when the checks are up again, and when another check joins.
        $incident->update(['status' => IncidentStatus::Monitoring]);
        $incident->servers()->attach($server = Server::factory()->create());

        $form->fillForm(['title' => 'By hand'])
            ->call('save')
            ->assertNotified('Dit incident is intussen gewijzigd.');

        $incident->refresh();
        $this->assertSame(['Title', IncidentStatus::Monitoring, [$server->id]], [$incident->title, $incident->status, $incident->servers()->pluck('servers.id')->all()]);

        Livewire::test(EditIncident::class, ['record' => $incident->getRouteKey()])
            ->fillForm(['title' => 'By hand'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['By hand', IncidentStatus::Monitoring, [$server->id]], [$incident->refresh()->title, $incident->status, $incident->servers()->pluck('servers.id')->all()]);
    }

    public function test_a_save_that_deadlocks_saves_nothing_says_so_and_saves_the_next_time(): void
    {
        $this->signIn();
        $incident = $this->incident();
        $deadlocks = 1;

        Incident::saving(function () use (&$deadlocks): void {
            if ($deadlocks-- > 0) {
                throw new QueryException('mysql', 'update incidents', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        $form = Livewire::test(EditIncident::class, ['record' => $incident->getRouteKey()])
            ->fillForm(['title' => 'Storage stall'])
            ->call('save')
            ->assertNotified('Iemand anders sloeg tegelijk op.');
        $this->assertSame('Title', $incident->refresh()->title);

        $form->call('save')->assertHasNoFormErrors();
        $this->assertSame('Storage stall', $incident->refresh()->title);
    }

    public function test_an_incident_knows_who_wrote_it_down(): void
    {
        $user = $this->signIn();

        $this->assertSame($user->id, Incident::factory()->create()->user_id);
        $this->assertSame($user->name, Incident::factory()->create()->recordedBy());

        auth()->logout();
        $this->assertNull(Incident::factory()->create()->recordedBy());
    }

    public function test_the_panel_shows_who_wrote_an_incident_an_entry_and_an_action_down_and_through_which_agent(): void
    {
        $this->signIn();
        $ada = User::factory()->create(['name' => 'Ada']);
        // Two of each: a lazy load would fail in strict mode.
        foreach (range(1, 2) as $index) {
            $incident = Incident::factory()->create(['user_id' => $ada->id]);
            $incident->forceFill(['recorded_via' => 'deploy-agent'])->save();
            $incident->timelineEntries()->create(['occurred_at' => now(), 'note' => "Entry {$index}", 'user_id' => $ada->id, 'recorded_via' => 'deploy-agent']);
            $action = $incident->actions()->create(['description' => "Action {$index}"]);
            $action->forceFill(['user_id' => $ada->id, 'recorded_via' => 'deploy-agent'])->save();
        }

        Livewire::test(ViewIncident::class, ['record' => $incident->getRouteKey()])->assertSee('Ada via deploy-agent');
        Livewire::test(ListIncidents::class)->toggleAllTableColumns()->assertSee('Ada via deploy-agent');
        Livewire::test(TimelineEntriesRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])->assertSee('Ada via deploy-agent');
        Livewire::test(ActionsRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])->toggleAllTableColumns()->assertSee('Ada via deploy-agent');
    }

    public function test_who_completed_an_action_item_is_kept_wherever_it_was_completed_and_cleared_when_it_is_reopened(): void
    {
        $user = $this->signIn();
        $user->forceFill(['name' => 'Grace'])->save();
        $incident = Incident::factory()->create();
        // Two: a lazy load of who completed them would fail in strict mode.
        $first = $incident->actions()->create(['description' => 'Alert on NFS latency']);
        $second = $incident->actions()->create(['description' => 'Add a second NFS server']);
        $first->forceFill(['user_id' => null])->save();
        $second->forceFill(['user_id' => null])->save();

        $manager = Livewire::test(ActionsRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => ViewIncident::class])
            ->callAction(TestAction::make('complete')->table($first));
        Livewire::test(OpenIncidentActions::class)->callAction(TestAction::make('complete')->table($second));

        foreach ([$first, $second] as $action) {
            $this->assertSame(['2026-11-09 12:00:00', $user->id, null], [$action->refresh()->done_at?->toDateTimeString(), $action->completed_by_id, $action->completed_via]);
        }

        $manager->toggleAllTableColumns()->assertSee('Grace');

        $manager->callAction(TestAction::make('reopen')->table($first));
        $this->assertSame([null, null, null], [$first->refresh()->done_at, $first->completed_by_id, $first->completed_via]);

        // Deleted after the page was shown: completing it does nothing.
        $gone = $incident->actions()->create(['description' => 'Gone']);
        IncidentAction::query()->whereKey($gone->id)->delete();
        $this->assertFalse($gone->complete());
    }

    private function incident(): Incident
    {
        return Incident::factory()->create([
            'title' => 'Title',
            'severity' => IncidentSeverity::Sev3,
            'status' => IncidentStatus::Open,
            'occurred_on' => '2026-11-09',
            'started_at' => null,
            'detected_at' => null,
            'resolved_at' => null,
            'impact' => null,
            'root_cause' => null,
            'body' => null,
        ]);
    }
}
