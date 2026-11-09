<?php

namespace Tests\Feature\Filament;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Filament\Resources\Incidents\Pages\EditIncident;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
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
