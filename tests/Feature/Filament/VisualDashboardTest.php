<?php

namespace Tests\Feature\Filament;

use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Filament\Widgets\ProjectBoard;
use App\Filament\Widgets\RenewalCalendar;
use App\Filament\Widgets\StatusOverview;
use App\Models\Check;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerDependency;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class VisualDashboardTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_the_dashboard_renders_with_the_demo_data(): void
    {
        $this->signIn();
        $this->seed(DemoSeeder::class);

        $this->get('/')->assertSuccessful();

        Livewire::test(StatusOverview::class)->assertSee(['1 storing', 'Open incidenten', 'Verlengingen']);
        Livewire::test(ProjectBoard::class)->assertSee(['Pasture Planner', 'Storing', 'Gepauzeerd', 'Geen bewaking', 'herd-app']);
        Livewire::test(RenewalCalendar::class)->assertSee(['pastureplanner.test', 'cartercycles.test', 'Verlopen']);
    }

    public function test_the_project_tiles_put_an_outage_first_and_leave_archived_projects_out(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $this->projectWithHttpCheck('Aardappel', CheckState::Up);
        $this->projectWithHttpCheck('Zuurkool', CheckState::Down);
        Project::factory()->create(['name' => 'Oud spul', 'status' => ProjectStatus::Archived]);

        Livewire::test(ProjectBoard::class)
            ->assertSeeInOrder(['Zuurkool', 'Storing', 'Aardappel', 'In orde'])
            ->assertDontSee('Oud spul');
    }

    public function test_the_status_band_counts_what_needs_attention(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $project = $this->projectWithHttpCheck('Zuurkool', CheckState::Down);
        $project->hostnames()->first()->checks()->first()->results()->create(['checked_at' => now()->subMinute(), 'ok' => false]);
        Incident::factory()->create(['title' => 'Alles plat', 'severity' => IncidentSeverity::Sev1]);
        Domain::factory()->create(['name' => 'bijna.test', 'expires_on' => '2026-11-01', 'auto_renews' => false]);

        Livewire::test(StatusOverview::class)
            ->assertSee(['1 storing', '0/1', '1 down', 'SEV1 · kritiek: Alles plat', 'Eerst: bijna.test, 01-11-2026', 'Nog niet ingericht']);
    }

    public function test_the_calendar_places_what_expires_on_the_line_and_stacks_what_overlaps(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        Domain::factory()->create(['name' => 'eerste.test', 'expires_on' => '2026-11-01']);
        Domain::factory()->create(['name' => 'tweede.test', 'expires_on' => '2026-11-02']);
        Server::factory()->create(['name' => 'later-01', 'expires_on' => '2027-06-01']);
        Domain::factory()->create(['name' => 'opgezegd.test', 'expires_on' => '2026-11-01', 'renewal_stopped_at' => '2026-10-01 10:00:00']);

        $component = Livewire::test(RenewalCalendar::class);

        $component->assertSee(['eerste.test', 'tweede.test'])->assertDontSee(['later-01', 'opgezegd.test']);
        $this->assertStringContainsString('--hb-cal-rows: 2', $component->html());
    }

    public function test_the_status_band_says_so_when_the_checks_stopped_running(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $project = $this->projectWithHttpCheck('Zuurkool', CheckState::Up);
        $project->hostnames()->first()->checks()->first()->results()->create(['checked_at' => '2026-10-23 11:30:00', 'ok' => true]);

        Livewire::test(StatusOverview::class)->assertSee('Metingen staan stil sinds 13:30');
    }

    public function test_a_certificate_close_to_expiry_is_a_warning_in_the_band_not_an_outage(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $project = $this->projectWithHttpCheck('Zuurkool', CheckState::Up);
        $hostname = $project->hostnames()->first();
        $hostname->checks()->first()->results()->create(['checked_at' => now()->subMinute(), 'ok' => true]);
        $tls = Check::factory()->for($hostname)->ofType(CheckType::Tls, $hostname->fqdn)->create();
        $tls->forceFill(['state' => CheckState::Down, 'last_checked_at' => now(), 'last_state_changed_at' => now()])->saveQuietly();

        Livewire::test(StatusOverview::class)
            ->assertSee(['1 let op', '1 certificaat of DNS'])
            ->assertDontSee('1 down');
    }

    public function test_a_tile_says_when_a_check_has_no_recent_result(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $project = $this->projectWithHttpCheck('Zuurkool', CheckState::Up);
        $project->hostnames()->first()->checks()->first()->forceFill(['last_checked_at' => '2026-10-23 10:00:00'])->saveQuietly();

        Livewire::test(ProjectBoard::class)->assertSee(['Let op', 'geen recente meting', 'Sinds 12:00']);
    }

    public function test_the_status_band_is_not_green_when_nothing_is_watched(): void
    {
        $this->signIn();
        Environment::factory()->for(Project::factory()->create(['name' => 'Kaal']))->create();

        Livewire::test(StatusOverview::class)->assertSee(['Geen bewaking', '0 van 1 in orde', 'Geen actieve checks']);
    }

    public function test_a_project_that_needs_no_checks_is_not_a_gap_in_the_status_band(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $this->projectWithHttpCheck('Aardappel', CheckState::Up);
        Environment::factory()->for(Project::factory()->create(['name' => 'Office wiki', 'unmonitored' => true]))->create();

        Livewire::test(StatusOverview::class)->assertSee(['In orde', '1 van 1 in orde']);
        Livewire::test(ProjectBoard::class)->assertSee(['Aardappel', 'In orde'])->assertDontSee(['Office wiki', 'Bewaking niet nodig']);
    }

    public function test_a_project_that_needs_no_checks_but_has_them_keeps_its_tile(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $this->projectWithHttpCheck('Office wiki', CheckState::Up)->update(['unmonitored' => true]);

        Livewire::test(ProjectBoard::class)->assertSee(['Office wiki', 'In orde']);
    }

    public function test_the_status_band_says_so_when_no_project_is_watched_on_purpose(): void
    {
        $this->signIn();
        Environment::factory()->for(Project::factory()->create(['name' => 'Office wiki', 'unmonitored' => true]))->create();

        Livewire::test(StatusOverview::class)
            ->assertSee('Bewaking niet nodig')
            ->assertDontSee('0 van 0');
        Livewire::test(ProjectBoard::class)->assertSee('Geen projecten met bewaking')->assertDontSee('Office wiki');
    }

    public function test_a_tile_names_the_server_whose_check_fails_also_one_reached_through_a_dependency(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $project = $this->projectWithHttpCheck('Zuurkool', CheckState::Up);
        $app = Server::factory()->create(['name' => 'app-01']);
        $storage = Server::factory()->create(['name' => 'opslag-01']);
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        EnvironmentServer::create(['environment_id' => $project->environments()->first()->id, 'server_id' => $app->id, 'role' => ServerRole::App]);
        $ping = Check::factory()->forServer($storage)->create();
        $ping->forceFill(['state' => CheckState::Down, 'last_checked_at' => now(), 'last_state_changed_at' => now(), 'last_error' => 'Geen antwoord'])->saveQuietly();

        Livewire::test(ProjectBoard::class)->assertSee(['Storing', 'opslag-01 · '.$ping->name.': Geen antwoord']);
    }

    public function test_a_tile_shows_what_production_depends_on_and_the_incidents_of_those_servers(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        $project = $this->projectWithHttpCheck('Zuurkool', CheckState::Up);
        $app = Server::factory()->create(['name' => 'app-01']);
        $storage = Server::factory()->create(['name' => 'opslag-01']);
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        EnvironmentServer::create(['environment_id' => $project->environments()->first()->id, 'server_id' => $app->id, 'role' => ServerRole::App]);
        Incident::factory()->create()->servers()->attach($storage);
        Incident::factory()->create(['status' => IncidentStatus::Monitoring])->projects()->attach($project);

        Livewire::test(ProjectBoard::class)
            ->assertSee(['app-01', 'via opslag-01', '1 open incident', '1 gemonitord incident', 'Op de kaart']);
    }

    public function test_a_check_with_neither_hostname_nor_server_does_not_break_the_dashboard(): void
    {
        $this->signIn();
        $this->projectWithHttpCheck('Zuurkool', CheckState::Up);
        Check::factory()->forServer()->create()->forceFill(['server_id' => null])->saveQuietly();

        Livewire::test(ProjectBoard::class)->assertSee('Zuurkool');
    }

    public function test_near_the_end_of_the_calendar_labels_go_left_and_do_not_overlap(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-23 12:00:00');
        Domain::factory()->create(['name' => 'dag64.test', 'expires_on' => '2026-12-26']);
        Domain::factory()->create(['name' => 'dag79.test', 'expires_on' => '2027-01-10']);

        $html = Livewire::test(RenewalCalendar::class)->html();

        $this->assertStringContainsString('--hb-cal-rows: 2', $html);
        $this->assertStringContainsString('hb-cal-marker-flip', $html);
    }

    private function projectWithHttpCheck(string $name, CheckState $state): Project
    {
        $project = Project::factory()->create(['name' => $name]);
        $environment = Environment::factory()->for($project)->create();
        $check = Check::factory()->for(Hostname::factory()->create(['environment_id' => $environment->id]))->create();
        $check->forceFill(['state' => $state, 'last_checked_at' => now(), 'last_state_changed_at' => now()->subMinutes(5)])->saveQuietly();

        return $project;
    }
}
