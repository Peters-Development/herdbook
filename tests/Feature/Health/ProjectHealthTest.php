<?php

namespace Tests\Feature\Health;

use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Enums\HealthState;
use App\Enums\ServerRole;
use App\Models\Check;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Services\Health\HealthReport;
use App\Services\Health\ProjectHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class ProjectHealthTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Environment $production;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-23 12:00:00');
        $this->project = Project::factory()->create();
        $this->production = Environment::factory()->for($this->project)->create();
    }

    public function test_a_project_without_checks_is_unwatched(): void
    {
        $this->assertSame(HealthState::Unwatched, $this->health()->state);
    }

    public function test_a_project_that_needs_no_checks_is_unmonitored_and_so_are_its_environments(): void
    {
        $this->project->update(['unmonitored' => true]);
        $staging = Environment::factory()->for($this->project)->create(['name' => 'Staging', 'kind' => 'staging']);

        $this->assertSame(HealthState::Unmonitored, $this->health()->state);
        $this->assertSame(HealthState::Unmonitored, app(ProjectHealth::class)->forEnvironment($staging->id)->state);
        $this->assertSame(HealthState::Unwatched, app(ProjectHealth::class)->forProject(Project::factory()->create())->state, 'Another project without checks is still a gap.');
    }

    public function test_checks_count_also_on_a_project_that_needs_none(): void
    {
        $this->project->update(['unmonitored' => true]);
        $this->hostnameCheck(CheckType::Http, CheckState::Down, changedAt: '2026-10-23 11:40:00');

        $this->assertSame(HealthState::Down, $this->health()->state);
    }

    public function test_all_checks_up_is_up(): void
    {
        $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $this->hostnameCheck(CheckType::Tls, CheckState::Up);

        $this->assertSame(HealthState::Up, $this->health()->state);
    }

    public function test_an_availability_check_down_is_an_outage_since_it_went_down(): void
    {
        $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $down = $this->hostnameCheck(CheckType::Http, CheckState::Down, changedAt: '2026-10-23 11:40:00');
        $this->hostnameCheck(CheckType::Keyword, CheckState::Down, changedAt: '2026-10-23 11:50:00');

        $health = $this->health();

        $this->assertSame(HealthState::Down, $health->state);
        $this->assertSame('2026-10-23 11:40:00', $health->since->toDateTimeString());
        $this->assertTrue($health->problems->first()->is($down));
    }

    public function test_the_first_problem_is_the_one_since_is_about(): void
    {
        $recent = $this->hostnameCheck(CheckType::Http, CheckState::Down, changedAt: '2026-10-23 11:00:00');
        $old = $this->hostnameCheck(CheckType::Http, CheckState::Down, changedAt: '2026-10-20 08:00:00');

        $health = $this->health();

        $this->assertTrue($health->problems->first()->is($old));
        $this->assertSame('2026-10-20 08:00:00', $health->since->toDateTimeString());
        $this->assertTrue($health->problems->last()->is($recent));
    }

    public function test_each_problem_is_listed_once_the_outage_first(): void
    {
        $pending = $this->hostnameCheck(CheckType::Http, CheckState::Pending);
        $down = $this->hostnameCheck(CheckType::Http, CheckState::Down);
        $this->hostnameCheck(CheckType::Http, CheckState::Up);

        $problems = $this->health()->problems;

        $this->assertSame([$down->id, $pending->id], $problems->pluck('id')->all());
    }

    public function test_a_certificate_or_dns_check_down_is_a_warning_not_an_outage(): void
    {
        $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $this->hostnameCheck(CheckType::Tls, CheckState::Down);

        $this->assertSame(HealthState::Degraded, $this->health()->state);
    }

    public function test_a_check_in_doubt_is_a_warning(): void
    {
        $this->hostnameCheck(CheckType::Http, CheckState::Pending);

        $this->assertSame(HealthState::Degraded, $this->health()->state);
    }

    public function test_a_check_without_a_recent_result_is_a_warning_since_its_last_result(): void
    {
        $this->hostnameCheck(CheckType::Http, CheckState::Up, checkedAt: '2026-10-23 10:00:00');

        $health = $this->health();

        $this->assertSame(HealthState::Degraded, $health->state);
        $this->assertSame('2026-10-23 10:00:00', $health->since->toDateTimeString());
    }

    public function test_paused_checks_do_not_count_and_only_paused_checks_is_paused(): void
    {
        $this->hostnameCheck(CheckType::Http, CheckState::Down, enabled: false);

        $this->assertSame(HealthState::Paused, $this->health()->state);

        $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $this->app->forgetScopedInstances(); // a new request

        $this->assertSame(HealthState::Up, $this->health()->state);
    }

    public function test_only_checks_that_never_ran_have_no_measurement_yet(): void
    {
        $this->hostnameCheck(CheckType::Http, CheckState::Unknown, changedAt: null, checkedAt: null, createdAt: '2026-10-23 11:59:00');

        $this->assertSame(HealthState::Unknown, $this->health()->state);
    }

    public function test_the_project_is_its_production_staging_does_not_count(): void
    {
        $staging = Environment::factory()->for($this->project)->staging()->create();
        $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $this->hostnameCheck(CheckType::Http, CheckState::Down, environment: $staging);

        $this->assertSame(HealthState::Up, $this->health()->state);
        $this->assertSame(HealthState::Down, app(ProjectHealth::class)->forEnvironment($staging)->state);
    }

    public function test_a_server_check_counts_for_every_environment_on_that_server(): void
    {
        $server = Server::factory()->create();
        $other = Environment::factory()->create();
        EnvironmentServer::create(['environment_id' => $this->production->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        EnvironmentServer::create(['environment_id' => $other->id, 'server_id' => $server->id, 'role' => ServerRole::Database]);
        $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $this->state(Check::factory()->forServer($server, CheckType::Ping)->create(), CheckState::Down);

        $this->assertSame(HealthState::Down, $this->health()->state);
        $this->assertSame(HealthState::Down, app(ProjectHealth::class)->forProject($other->project_id)->state);
    }

    public function test_a_check_on_a_server_that_production_depends_on_counts(): void
    {
        $app = Server::factory()->create();
        $storage = Server::factory()->create();
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        EnvironmentServer::create(['environment_id' => $this->production->id, 'server_id' => $app->id, 'role' => ServerRole::App]);
        $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $this->state(Check::factory()->forServer($storage, CheckType::Ping)->create(), CheckState::Down);

        $this->assertSame(HealthState::Down, $this->health()->state);
    }

    public function test_the_response_time_is_the_average_of_the_last_hour_of_the_http_checks_that_are_up(): void
    {
        $fast = $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $slow = $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $down = $this->hostnameCheck(CheckType::Http, CheckState::Down);
        $tls = $this->hostnameCheck(CheckType::Tls, CheckState::Up);
        $fast->results()->createMany([
            ['checked_at' => '2026-10-23 11:30:00', 'ok' => true, 'response_ms' => 80],
            ['checked_at' => '2026-10-23 11:40:00', 'ok' => true, 'response_ms' => 120],
            ['checked_at' => '2026-10-23 10:30:00', 'ok' => true, 'response_ms' => 5000], // over an hour ago
            ['checked_at' => '2026-10-23 11:50:00', 'ok' => false, 'response_ms' => 9000], // a failure says nothing about speed
        ]);
        $slow->results()->create(['checked_at' => '2026-10-23 11:30:00', 'ok' => true, 'response_ms' => 301]);
        $down->results()->create(['checked_at' => '2026-10-23 11:30:00', 'ok' => true, 'response_ms' => 9000]);
        $tls->results()->create(['checked_at' => '2026-10-23 11:30:00', 'ok' => true, 'response_ms' => 9000]);
        // A check of a redirect times the redirect, not the page.
        $redirect = $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $redirect->forceFill(['settings' => ['redirects_to' => 'example.net']])->saveQuietly();
        $redirect->results()->create(['checked_at' => '2026-10-23 11:30:00', 'ok' => true, 'response_ms' => 9]);

        $this->assertSame(201, $this->health()->responseMs);
    }

    public function test_an_hour_bar_is_the_worst_check_of_that_hour(): void
    {
        $http = $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $other = $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $http->results()->createMany([
            ['checked_at' => '2026-10-23 11:10:00', 'ok' => false],
            ['checked_at' => '2026-10-23 11:20:00', 'ok' => false],
        ]);
        $other->results()->createMany([
            ['checked_at' => '2026-10-23 11:10:00', 'ok' => true],
            ['checked_at' => '2026-10-23 11:20:00', 'ok' => true],
            ['checked_at' => '2026-10-23 12:00:00', 'ok' => true],
        ]);

        $health = $this->health();

        $this->assertEquals(0, $health->hours[22]);
        $this->assertEquals(1, $health->hours[23]);
        $this->assertEquals(50.0, $health->uptime);
        $this->assertSame(['bad', 'ok'], array_slice($health->hourTones(), 22));
    }

    public function test_the_hour_bars_count_the_results_of_the_availability_checks(): void
    {
        $http = $this->hostnameCheck(CheckType::Http, CheckState::Up);
        $tls = $this->hostnameCheck(CheckType::Tls, CheckState::Down);
        $http->results()->createMany([
            ['checked_at' => '2026-10-23 11:10:00', 'ok' => true],
            ['checked_at' => '2026-10-23 11:20:00', 'ok' => false],
            ['checked_at' => '2026-10-23 12:00:00', 'ok' => true],
        ]);
        $tls->results()->create(['checked_at' => '2026-10-23 11:30:00', 'ok' => false]);

        $health = $this->health();

        $this->assertCount(24, $health->hours);
        $this->assertNull($health->hours[0]);
        $this->assertSame(0.5, $health->hours[22]);
        $this->assertEquals(1, $health->hours[23]);
        $this->assertEquals(75.0, $health->uptime);
    }

    private function health(): HealthReport
    {
        return app(ProjectHealth::class)->forProject($this->project);
    }

    private function hostnameCheck(
        CheckType $type,
        CheckState $state,
        ?Environment $environment = null,
        bool $enabled = true,
        ?string $changedAt = '2026-10-23 09:00:00',
        ?string $checkedAt = '2026-10-23 11:59:30',
        ?int $responseMs = 120,
        string $createdAt = '2026-10-23 08:00:00',
    ): Check {
        $hostname = Hostname::factory()->create(['environment_id' => ($environment ?? $this->production)->id]);
        $check = Check::factory()->for($hostname)->ofType($type, $type->isHttp() ? "https://{$hostname->fqdn}/" : $hostname->fqdn)->create([
            'enabled' => $enabled,
        ]);

        return $this->state($check, $state, $changedAt, $checkedAt, $responseMs, $createdAt);
    }

    private function state(
        Check $check,
        CheckState $state,
        ?string $changedAt = '2026-10-23 09:00:00',
        ?string $checkedAt = '2026-10-23 11:59:30',
        ?int $responseMs = 120,
        string $createdAt = '2026-10-23 08:00:00',
    ): Check {
        $check->forceFill([
            'state' => $state,
            'last_state_changed_at' => $changedAt,
            'last_checked_at' => $checkedAt,
            'last_response_ms' => $responseMs,
            'created_at' => $createdAt,
        ])->saveQuietly();

        return $check;
    }
}
