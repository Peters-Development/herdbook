<?php

namespace Tests\Feature\Filament;

use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Enums\IncidentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\ServerRole;
use App\Enums\TimelineRange;
use App\Filament\Resources\Projects\Pages\ViewProjectHealth;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\ProjectBoard;
use App\Models\Check;
use App\Models\CheckStat;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Project;
use App\Models\Server;
use App\Services\Checks\CheckHistory;
use App\Services\Health\HealthReport;
use App\Services\Health\ProjectHealth;
use App\Services\Health\ProjectTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ProjectStatusPageTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private Project $project;

    private Environment $production;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // 14:20 in Amsterdam (summer time).
        Date::setTestNow('2026-10-23 12:20:00');
        $this->project = Project::factory()->create(['name' => 'Studbook']);
        $this->production = Environment::factory()->for($this->project)->create();
        $this->server = Server::factory()->create(['name' => 'herd-app']);
        EnvironmentServer::create(['environment_id' => $this->production->id, 'server_id' => $this->server->id, 'role' => ServerRole::App]);
    }

    public function test_the_tile_leads_to_the_status_page_and_the_project_has_a_status_tab(): void
    {
        $this->signIn();
        $url = ProjectResource::getUrl('status', ['record' => $this->project]);

        Livewire::test(ProjectBoard::class)->assertSee($url, false);
        $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertSee(['Overzicht', 'Status'])->assertSee($url, false);
    }

    public function test_a_bar_is_the_worst_check_of_its_hour_and_each_check_has_its_own_strip(): void
    {
        $site = $this->hostnameCheck(CheckType::Http);
        $ping = Check::factory()->forServer($this->server)->create(['name' => 'Ping']);
        Check::factory()->forServer($this->server, CheckType::Tls)->create(['name' => 'Certificaat']);
        $this->stat($site, '2026-10-23 09:00:00', up: 60, down: 0, ms: 200);
        $this->stat($ping, '2026-10-23 09:00:00', up: 30, down: 30, ms: 10);
        $this->stat($site, '2026-10-23 10:00:00', up: 60, down: 0, ms: 400);

        $timeline = app(ProjectTimeline::class)->build($this->project, TimelineRange::Day);
        $nine = $timeline->bucketAt(Date::parse('2026-10-23 09:30:00'));

        $this->assertCount(24, $timeline->buckets);
        $this->assertSame(0.5, $timeline->buckets[$nine]['share'], 'The ping, not the average.');
        $this->assertSame([200, 400], [$timeline->buckets[$nine]['ms'], $timeline->buckets[$nine + 1]['ms']], 'Only the website times.');
        $this->assertSame(75.0, $timeline->uptime);
        // Availability first, then by name; a server check says which server.
        $this->assertSame(['HTTP', 'herd-app · Ping', 'herd-app · Certificaat'], array_map(fn ($lane): string => $lane->label(), $timeline->lanes));
        $this->assertSame(50.0, $timeline->lanes[1]->uptime());
        $this->assertNull($timeline->lanes[2]->uptime());
    }

    public function test_days_are_calendar_days_in_amsterdam(): void
    {
        $site = $this->hostnameCheck(CheckType::Http);
        // 00:30 on 21 October in Amsterdam.
        $this->stat($site, '2026-10-20 22:00:00', up: 0, down: 12);

        $timeline = app(ProjectTimeline::class)->build($this->project, TimelineRange::Month);

        $this->assertCount(30, $timeline->buckets);
        $this->assertSame('2026-10-22 22:00', $timeline->buckets[29]['start']->format('Y-m-d H:i'), 'Today starts at midnight in Amsterdam.');
        $this->assertSame(0.0, $timeline->buckets[27]['share']);
        $this->assertSame('wo 21-10', $timeline->label(27));
        $this->assertNull($timeline->buckets[26]['share']);
    }

    public function test_the_maintenance_and_incidents_of_the_project_and_the_servers_it_needs_are_on_it(): void
    {
        $storage = Server::factory()->create();
        $this->server->dependencies()->create(['depends_on_id' => $storage->id, 'kind' => 'NFS']);
        $onStorage = $this->maintenance('Opslag vergroot', '2026-10-23 08:00:00', [$storage]);
        $onProject = Maintenance::factory()->create(['title' => 'Cache geleegd', 'starts_at' => '2026-10-23 07:00:00']);
        $onProject->projects()->attach($this->project);
        $this->maintenance('Andere server', '2026-10-23 08:00:00', [Server::factory()->create()]);
        $this->maintenance('Afgelast', '2026-10-23 08:00:00', [$this->server], MaintenanceStatus::Cancelled);
        $this->maintenance('Lang geleden', '2026-10-01 08:00:00', [$this->server]);
        $incident = Incident::factory()->create(['title' => 'Trage app', 'status' => IncidentStatus::Resolved, 'occurred_on' => '2026-10-23', 'started_at' => '2026-10-23 06:00:00', 'resolved_at' => '2026-10-23 07:00:00']);
        $incident->servers()->attach($this->server);

        $timeline = app(ProjectTimeline::class)->build($this->project, TimelineRange::Day);

        $this->assertSame(['Cache geleegd', 'Opslag vergroot'], $timeline->maintenance->pluck('title')->all());
        $this->assertSame(['Trage app'], $timeline->incidents->pluck('title')->all());
        $this->assertTrue(app(ProjectTimeline::class)->build($this->project, TimelineRange::Month)->maintenance->contains($onStorage));
    }

    public function test_opening_a_bar_shows_what_failed_with_its_errors_and_what_happened_then(): void
    {
        $this->signIn();
        $site = $this->hostnameCheck(CheckType::Http);
        $site->results()->createMany([
            ['checked_at' => '2026-10-23 11:10:00', 'ok' => false, 'error' => 'HTTP 502'],
            ['checked_at' => '2026-10-23 11:11:00', 'ok' => false, 'error' => 'HTTP 502'],
            ['checked_at' => '2026-10-23 11:12:00', 'ok' => false, 'error' => 'Time-out'],
            ['checked_at' => '2026-10-23 11:13:00', 'ok' => true, 'response_ms' => 300],
        ]);
        $site->events()->create(['from_state' => 'up', 'to_state' => 'down', 'occurred_at' => '2026-10-23 11:12:00', 'error' => 'HTTP 502']);
        $this->maintenance('Restart after the outage', '2026-10-23 11:30:00', [$this->server]);
        $this->maintenance('Vanochtend', '2026-10-23 06:00:00', [$this->server]);

        $page = Livewire::test(ViewProjectHealth::class, ['record' => $this->project->getRouteKey()])
            ->assertSee(['Vanochtend', 'Restart after the outage']);
        $eleven = Date::parse('2026-10-23 11:00:00')->getTimestamp();

        $page->call('select', $eleven)
            ->assertSee(['23-10 13:00–14:00', '3 van 4 mislukt', '25,00% geslaagd'])
            ->assertSeeText(['2× HTTP 502', '1× Time-out'])
            // The bar's own story; the maintenance of the morning is still a band above the bars.
            ->assertViewHas('feed', fn (array $feed): bool => array_column($feed, 'title') === ['Onderhoud: Restart after the outage', 'Down: HTTP'])
            ->call('select', $eleven)
            ->assertDontSee('3 van 4 mislukt')
            ->assertViewHas('feed', fn (array $feed): bool => count($feed) === 3);
    }

    public function test_a_project_without_checks_still_shows_its_maintenance(): void
    {
        $this->signIn();
        $this->project->update(['unmonitored' => true]);
        $this->maintenance('Updates', '2026-10-23 09:00:00', [$this->server]);
        $planned = $this->maintenance('Upgrade naar 24.04', '2026-10-30 20:00:00', [$this->server], MaintenanceStatus::Planned);

        $this->get(ProjectResource::getUrl('status', ['record' => $this->project]))
            ->assertOk()
            ->assertSee(['Status van Studbook', 'Bewaking niet nodig', 'Er zijn geen checks op productie: alleen onderhoud en incidenten.', 'Onderhoud: Updates', 'Upgrade naar 24.04'])
            ->assertDontSee('van de websites (HTTP-checks)');

        $this->assertTrue(app(ProjectTimeline::class)->nextMaintenance($this->project)->is($planned));
    }

    public function test_the_range_comes_from_the_address_and_an_unknown_one_is_a_day(): void
    {
        $this->signIn();
        $this->hostnameCheck(CheckType::Http);

        foreach (TimelineRange::cases() as $range) {
            $this->get(ProjectResource::getUrl('status', ['record' => $this->project, 'period' => $range->value]))->assertOk()->assertSee("afgelopen {$range->getLabel()}");
        }

        $this->get(ProjectResource::getUrl('status', ['record' => $this->project, 'period' => 'forever']))->assertOk()->assertSee('afgelopen 24 uur');
        Livewire::test(ViewProjectHealth::class, ['record' => $this->project->getRouteKey()])
            ->call('select', Date::parse('2026-10-23 09:00:00')->getTimestamp())
            ->set('range', '7d')
            ->assertSet('selected', null);
    }

    public function test_the_hour_history_can_carry_the_response_time(): void
    {
        $check = $this->hostnameCheck(CheckType::Http);
        $this->stat($check, '2026-10-23 10:00:00', up: 60, down: 0, ms: 250);
        $check->results()->createMany([
            ['checked_at' => '2026-10-23 11:10:00', 'ok' => true, 'response_ms' => 100],
            ['checked_at' => '2026-10-23 11:20:00', 'ok' => true, 'response_ms' => 300],
            ['checked_at' => '2026-10-23 11:30:00', 'ok' => false],
        ]);

        $hours = app(CheckHistory::class)->hourly([$check->id], 3, withResponse: true)[$check->id];

        $this->assertSame([['up' => 60, 'down' => 0, 'ms' => 250], ['up' => 2, 'down' => 1, 'ms' => 200], ['up' => 0, 'down' => 0, 'ms' => null]], $hours);
    }

    public function test_the_day_bars_are_the_tiles_bars_also_with_a_paused_check(): void
    {
        $site = $this->hostnameCheck(CheckType::Http);
        $paused = Check::factory()->forServer($this->server)->create(['name' => 'Ping', 'enabled' => false]);
        $this->stat($site, '2026-10-23 09:00:00', up: 50, down: 10);
        $this->stat($paused, '2026-10-23 10:00:00', up: 0, down: 60);

        $tile = app(ProjectHealth::class)->forProject($this->project);
        $timeline = app(ProjectTimeline::class)->build($this->project, TimelineRange::Day);

        $this->assertSame($tile->hours, array_column($timeline->buckets, 'share'));
        $this->assertSame($tile->uptime, $timeline->uptime);
        $this->assertSame('Ping', $timeline->lanes[1]->check->name, 'Shown, but it counts for nothing.');
    }

    public function test_a_day_bar_is_coloured_by_its_worst_hour(): void
    {
        $site = $this->hostnameCheck(CheckType::Http);
        // Twenty minutes down on the 21st, the rest of the day up.
        $this->stat($site, '2026-10-21 10:00:00', up: 40, down: 20);
        foreach (range(11, 20) as $hour) {
            $this->stat($site, "2026-10-21 {$hour}:00:00", up: 60, down: 0);
        }

        $day = app(ProjectTimeline::class)->build($this->project, TimelineRange::Month)->buckets[27];

        $this->assertGreaterThan(0.95, $day['share']);
        $this->assertSame('warn', HealthReport::tone($day['worst']));
    }

    public function test_the_day_the_clocks_go_back_has_25_hours_and_its_two_two_oclocks_are_told_apart(): void
    {
        Date::setTestNow('2026-10-26 12:00:00');
        $site = $this->hostnameCheck(CheckType::Http);
        // 02:00 summer time and 02:00 winter time.
        $this->stat($site, '2026-10-25 00:00:00', up: 60, down: 0);
        $this->stat($site, '2026-10-25 01:00:00', up: 0, down: 60);

        $month = app(ProjectTimeline::class)->build($this->project, TimelineRange::Month);
        $sunday = $month->bucketAt(Date::parse('2026-10-25 00:30:00'));

        $this->assertSame(25, (int) $month->buckets[$sunday]['start']->diffInHours($month->buckets[$sunday]['end']));
        $this->assertSame($sunday, $month->bucketAt(Date::parse('2026-10-25 01:30:00')));
        $this->assertSame(0.5, $month->buckets[$sunday]['share']);

        Date::setTestNow('2026-10-25 03:20:00');
        $day = app(ProjectTimeline::class)->build($this->project, TimelineRange::Day);

        $this->assertSame('25-10 02:00 CEST–02:00 CET', $day->label($day->bucketAt(Date::parse('2026-10-25 00:30:00'))));
    }

    public function test_an_open_incident_is_in_the_bars_it_spans_one_that_ended_before_the_range_is_not(): void
    {
        $this->signIn();
        $this->hostnameCheck(CheckType::Http);
        $open = Incident::factory()->create(['title' => 'Still open', 'status' => IncidentStatus::Open, 'occurred_on' => '2026-10-20', 'started_at' => '2026-10-20 08:00:00']);
        $open->projects()->attach($this->project);
        $old = Incident::factory()->create(['title' => 'Vanochtend vroeg opgelost', 'status' => IncidentStatus::Resolved, 'occurred_on' => '2026-10-22', 'started_at' => '2026-10-22 06:00:00', 'resolved_at' => '2026-10-22 07:00:00']);
        $old->projects()->attach($this->project);

        $timeline = app(ProjectTimeline::class)->build($this->project, TimelineRange::Day);
        $this->assertSame(['Still open'], $timeline->incidents->pluck('title')->all());

        Livewire::test(ViewProjectHealth::class, ['record' => $this->project->getRouteKey()])
            ->call('select', Date::parse('2026-10-23 10:00:00')->getTimestamp())
            ->assertViewHas('feed', fn (array $feed): bool => array_column($feed, 'title') === ['Incident: Still open']);
    }

    public function test_the_open_bar_stays_the_same_hour_when_the_hours_move_on(): void
    {
        $this->signIn();
        $site = $this->hostnameCheck(CheckType::Http);
        $site->results()->create(['checked_at' => '2026-10-23 11:10:00', 'ok' => false, 'error' => 'HTTP 502']);

        $page = Livewire::test(ViewProjectHealth::class, ['record' => $this->project->getRouteKey()])
            ->call('select', Date::parse('2026-10-23 11:00:00')->getTimestamp())
            ->assertSee('Wat er gebeurde: 23-10 13:00–14:00');

        Date::setTestNow('2026-10-23 13:05:00');
        $page->call('$refresh')->assertSee('Wat er gebeurde: 23-10 13:00–14:00');

        // A day later the hour is out of the range: closed.
        Date::setTestNow('2026-10-24 12:05:00');
        $page->call('$refresh')->assertSet('selected', null)->assertDontSee('Wat er gebeurde: 23-10');
    }

    public function test_the_response_time_is_of_the_pages_not_of_redirects_or_paused_checks(): void
    {
        $site = $this->hostnameCheck(CheckType::Http);
        $redirect = $this->hostnameCheck(CheckType::Http, 'www.studbook.test');
        $redirect->update(['settings' => ['redirects_to' => 'app.studbook.test']]);
        $paused = $this->hostnameCheck(CheckType::Http, 'old.studbook.test');
        $paused->update(['enabled' => false]);
        $this->stat($site, '2026-10-23 09:00:00', up: 60, down: 0, ms: 200);
        $this->stat($redirect, '2026-10-23 09:00:00', up: 60, down: 0, ms: 20);
        $this->stat($paused, '2026-10-23 09:00:00', up: 60, down: 0, ms: 900);

        $this->assertSame(200, app(ProjectTimeline::class)->build($this->project, TimelineRange::Day)->responseMs);
    }

    public function test_a_quarter_without_stats_does_not_query_every_hour(): void
    {
        $this->hostnameCheck(CheckType::Http);
        Check::factory()->forServer($this->server)->create(['enabled' => false]);

        DB::enableQueryLog();
        app(ProjectTimeline::class)->build($this->project, TimelineRange::Quarter);

        $this->assertLessThan(30, count(DB::getQueryLog()));
    }

    public function test_an_old_bar_has_its_counts_but_no_longer_its_errors(): void
    {
        $this->signIn();
        $site = $this->hostnameCheck(CheckType::Http);
        $this->stat($site, '2026-10-03 10:00:00', up: 50, down: 10);

        Livewire::withQueryParams(['period' => '30d'])
            ->test(ViewProjectHealth::class, ['record' => $this->project->getRouteKey()])
            ->call('select', Date::parse('2026-10-02 22:00:00')->getTimestamp())
            ->assertSee(['10 van 60 mislukt', 'alleen de aantallen over', 'za 03-10']);
    }

    public function test_overlapping_bands_go_on_their_own_row_and_overdue_work_is_in_the_summary(): void
    {
        $this->signIn();
        $this->maintenance('Eerste', '2026-10-23 08:00:00', [$this->server]);
        $this->maintenance('Tweede', '2026-10-23 08:10:00', [$this->server]);
        $this->maintenance('Vergeten', '2026-10-22 20:00:00', [$this->server], MaintenanceStatus::Planned);

        Livewire::test(ViewProjectHealth::class, ['record' => $this->project->getRouteKey()])
            ->assertViewHas('maintenanceBands', fn (array $stack): bool => $stack['rows'] === 2 && array_column($stack['bands'], 'row') === [0, 0, 1])
            ->assertSee('Over tijd · Vergeten');
        $this->get(ProjectResource::getUrl('edit', ['record' => $this->project]))->assertSee(['Overzicht', 'Status', 'Bewerken']);
    }

    public function test_a_band_near_the_right_edge_is_stacked_where_it_is_drawn_and_planned_work_is_not_history(): void
    {
        $this->signIn();
        $this->hostnameCheck(CheckType::Http);
        $window = Maintenance::factory()->create(['title' => 'Venster', 'starts_at' => '2026-10-23 06:00:00', 'ends_at' => '2026-10-23 07:20:00']);
        $window->servers()->attach($this->server);
        // A moment just after it: near the right edge it grows to the left, over the window.
        $this->maintenance('Moment', '2026-10-23 07:40:00', [$this->server]);
        $this->maintenance('Straks', '2026-10-23 12:40:00', [$this->server], MaintenanceStatus::Planned);

        Livewire::test(ViewProjectHealth::class, ['record' => $this->project->getRouteKey()])
            ->assertViewHas('maintenanceBands', fn (array $stack): bool => array_column($stack['bands'], 'row', 'label') === ['Venster' => 0, 'Moment' => 1, 'Straks' => 0])
            ->assertViewHas('feed', fn (array $feed): bool => array_column($feed, 'title') === ['Onderhoud: Moment', 'Onderhoud: Venster'])
            ->assertSee('Per balk de slechtste check van dat uur')
            ->set('range', '30d')
            ->assertSee(['Per balk de slechtste check van die dag', 'vorige of volgende dag']);
    }

    private function hostnameCheck(CheckType $type, string $fqdn = 'app.studbook.test'): Check
    {
        $check = Check::factory()->ofType($type, "https://{$fqdn}/")->for(Hostname::factory()->create(['fqdn' => $fqdn, 'environment_id' => $this->production->id]))->create(['name' => $type->getLabel()]);
        $check->forceFill(['state' => CheckState::Up, 'last_checked_at' => now()])->saveQuietly();

        return $check;
    }

    private function stat(Check $check, string $hour, int $up, int $down, ?int $ms = null): void
    {
        CheckStat::create(['check_id' => $check->id, 'granularity' => CheckStat::HOUR, 'period_start' => $hour, 'up_count' => $up, 'down_count' => $down, 'avg_ms' => $ms]);
    }

    /**
     * @param  list<Server>  $servers
     */
    private function maintenance(string $title, string $at, array $servers, MaintenanceStatus $status = MaintenanceStatus::Done): Maintenance
    {
        $maintenance = Maintenance::factory()->create(['title' => $title, 'starts_at' => $at, 'status' => $status]);
        $maintenance->servers()->attach(collect($servers)->pluck('id'));

        return $maintenance;
    }
}
