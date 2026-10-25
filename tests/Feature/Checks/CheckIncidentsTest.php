<?php

namespace Tests\Feature\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Services\Checks\CheckIncidents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class CheckIncidentsTest extends TestCase
{
    use RefreshDatabase;

    private function downCheck(Environment $environment, AlertLevel $level = AlertLevel::Critical, string $wentDownAt = '2026-10-22 13:07:00'): Check
    {
        $hostname = Hostname::factory()->create(['environment_id' => $environment->id]);
        $check = Check::factory()->create(['hostname_id' => $hostname->id, 'alert_level' => $level, 'name' => "HTTP {$hostname->fqdn}"]);
        $check->forceFill(['state' => CheckState::Down])->save();

        foreach (['13:00:00' => true, '13:04:00' => false, '13:05:00' => false, '13:07:00' => false] as $time => $ok) {
            CheckResult::create(['check_id' => $check->id, 'checked_at' => "2026-10-22 {$time}", 'ok' => $ok]);
        }

        $check->events()->create(['from_state' => CheckState::Pending, 'to_state' => CheckState::Down, 'occurred_at' => $wentDownAt, 'error' => 'HTTP 503']);

        return $check;
    }

    public function test_a_critical_production_outage_opens_a_draft_incident_after_five_minutes(): void
    {
        $environment = Environment::factory()->create();
        $server = Server::factory()->create();
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        $check = $this->downCheck($environment);

        Date::setTestNow('2026-10-22 13:11:00');
        $this->artisan('checks:incidents')->assertSuccessful();
        $this->assertSame(0, Incident::count());

        Date::setTestNow('2026-10-22 13:12:00');
        $this->artisan('checks:incidents')->assertSuccessful();

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentSource::Check, $incident->source);
        // started at the first failure after the last good result, detected when it went down
        $this->assertSame('2026-10-22 13:04:00', $incident->started_at->toDateTimeString());
        $this->assertSame('2026-10-22 13:07:00', $incident->detected_at->toDateTimeString());
        $this->assertTrue($incident->projects()->sole()->is($environment->project));
        $this->assertTrue($incident->servers()->sole()->is($server));
        $this->assertSame($incident->id, $check->events()->sole()->incident_id);
    }

    public function test_a_storage_server_going_down_opens_an_incident_for_what_mounts_it(): void
    {
        $environment = Environment::factory()->create();
        $app = Server::factory()->create();
        $storage = Server::factory()->create();
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $app->id, 'role' => ServerRole::App]);
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        $ping = Check::factory()->forServer($storage)->critical()->create();
        $ping->forceFill(['state' => CheckState::Down])->save();
        $ping->events()->create(['from_state' => CheckState::Pending, 'to_state' => CheckState::Down, 'occurred_at' => '2026-10-22 13:07:00', 'error' => 'Geen antwoord']);

        Date::setTestNow('2026-10-22 13:13:00');
        $this->artisan('checks:incidents')->assertSuccessful();

        $incident = Incident::query()->sole();
        $this->assertTrue($incident->projects()->sole()->is($environment->project));
        $this->assertTrue($incident->servers()->sole()->is($storage));
    }

    public function test_a_server_check_joining_an_incident_brings_its_other_projects_and_itself(): void
    {
        $a = Environment::factory()->create();
        $b = Environment::factory()->create();
        $archived = Environment::factory()->create();
        $archived->project->update(['status' => ProjectStatus::Archived]);
        $storage = Server::factory()->create();

        foreach ([$a, $b, $archived] as $environment) {
            $app = Server::factory()->create();
            EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $app->id, 'role' => ServerRole::App]);
            ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        }

        // Project A's site went down first: its incident is open.
        $this->downCheck($a);
        Date::setTestNow('2026-10-22 13:13:00');
        $this->artisan('checks:incidents')->assertSuccessful();

        // Then the storage they all mount.
        $ping = Check::factory()->forServer($storage)->critical()->create();
        $ping->forceFill(['state' => CheckState::Down])->save();
        $ping->events()->create(['from_state' => CheckState::Pending, 'to_state' => CheckState::Down, 'occurred_at' => '2026-10-22 13:14:00', 'error' => 'Geen antwoord']);
        Date::setTestNow('2026-10-22 13:20:00');
        $this->artisan('checks:incidents')->assertSuccessful();

        $incident = Incident::query()->sole();
        $this->assertEqualsCanonicalizing([$a->project_id, $b->project_id], $incident->projects()->pluck('projects.id')->all());
        $this->assertTrue($incident->servers()->whereKey($storage->id)->exists());
    }

    public function test_a_second_check_of_the_same_project_joins_the_open_incident(): void
    {
        $environment = Environment::factory()->create();
        $this->downCheck($environment);
        $this->downCheck($environment);

        Date::setTestNow('2026-10-22 13:20:00');
        $this->artisan('checks:incidents')->assertSuccessful();

        $incident = Incident::query()->sole();
        $this->assertSame(2, $incident->timelineEntries()->count());
    }

    public function test_staging_and_normal_checks_open_no_incident(): void
    {
        $this->downCheck(Environment::factory()->staging()->create());
        $this->downCheck(Environment::factory()->create(), AlertLevel::Normal);

        Date::setTestNow('2026-10-22 13:30:00');
        $this->artisan('checks:incidents')->assertSuccessful();

        $this->assertSame(0, Incident::count());
    }

    public function test_a_check_that_is_up_again_opens_nothing(): void
    {
        $check = $this->downCheck(Environment::factory()->create());
        $check->forceFill(['state' => CheckState::Up])->save();

        Date::setTestNow('2026-10-22 13:30:00');
        $this->artisan('checks:incidents')->assertSuccessful();

        $this->assertSame(0, Incident::count());
    }

    public function test_a_deleted_draft_incident_does_not_come_back(): void
    {
        $environment = Environment::factory()->create();
        $check = Check::factory()->critical()->create(['hostname_id' => Hostname::factory()->create(['environment_id' => $environment->id])->id]);
        $check->forceFill(['state' => CheckState::Down])->save();
        $check->events()->create(['from_state' => CheckState::Pending, 'to_state' => CheckState::Down, 'occurred_at' => now()->subMinutes(10)]);

        app(CheckIncidents::class)->openForLongOutages(Date::now());
        Incident::query()->sole()->delete();
        app(CheckIncidents::class)->openForLongOutages(Date::now());

        $this->assertSame(0, Incident::count());
    }

    public function test_every_down_event_is_evaluated_once_whatever_the_outcome(): void
    {
        $environment = Environment::factory()->create();
        $hostname = Hostname::factory()->create(['environment_id' => $environment->id]);
        $normal = Check::factory()->create(['hostname_id' => $hostname->id, 'alert_level' => AlertLevel::Normal]);
        $normal->forceFill(['state' => CheckState::Down])->save();
        $event = $normal->events()->create(['from_state' => CheckState::Up, 'to_state' => CheckState::Down, 'occurred_at' => now()->subMinutes(10)]);

        app(CheckIncidents::class)->openForLongOutages(Date::now());

        $this->assertNotNull($event->fresh()->incident_evaluated_at);
        $this->assertNull($event->fresh()->incident_id);
    }

    public function test_a_paused_check_opens_no_incident(): void
    {
        [$check] = $this->criticalProductionOutage();
        $check->update(['enabled' => false]);

        app(CheckIncidents::class)->openForLongOutages(Date::now()->addMinutes(10));

        $this->assertSame(0, Incident::count());
    }

    public function test_joining_an_incident_in_monitoring_opens_it_again(): void
    {
        [$check, $environment] = $this->criticalProductionOutage();
        $incident = Incident::factory()->create(['status' => IncidentStatus::Monitoring, 'source' => IncidentSource::Check, 'detected_at' => now()->subHour()]);
        $incident->projects()->attach($environment->project_id);

        app(CheckIncidents::class)->openForLongOutages(Date::now()->addMinutes(10));

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertSame($incident->id, $check->events()->sole()->incident_id);
    }

    public function test_an_old_unresolved_incident_is_not_joined(): void
    {
        [, $environment] = $this->criticalProductionOutage();
        $stale = Incident::factory()->create(['source' => IncidentSource::Check, 'detected_at' => now()->subDays(3)]);
        $stale->projects()->attach($environment->project_id);

        app(CheckIncidents::class)->openForLongOutages(Date::now()->addMinutes(10));

        $this->assertSame(2, Incident::count());
    }

    public function test_an_incident_stays_open_while_another_of_its_checks_is_down(): void
    {
        [$first, $environment] = $this->criticalProductionOutage();
        [$second] = $this->criticalProductionOutage($environment);
        app(CheckIncidents::class)->openForLongOutages(Date::now()->addMinutes(10));
        $incident = Incident::query()->sole();

        $first->forceFill(['state' => CheckState::Up])->save();
        $up = $first->events()->create(['from_state' => CheckState::Down, 'to_state' => CheckState::Up, 'occurred_at' => now()]);
        app(CheckIncidents::class)->recovered($up);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    /**
     * @return array{Check, Environment}
     */
    private function criticalProductionOutage(?Environment $environment = null): array
    {
        $environment ??= Environment::factory()->create();
        $hostname = Hostname::factory()->create(['environment_id' => $environment->id]);
        $check = Check::factory()->critical()->create(['hostname_id' => $hostname->id]);
        $check->forceFill(['state' => CheckState::Down])->save();
        $check->events()->create(['from_state' => CheckState::Pending, 'to_state' => CheckState::Down, 'occurred_at' => now()]);

        return [$check, $environment];
    }
}
