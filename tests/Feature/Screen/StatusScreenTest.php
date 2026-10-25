<?php

namespace Tests\Feature\Screen;

use App\Enums\CheckState;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\ServerRole;
use App\Models\Check;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Models\StatusScreen;
use App\Services\Checks\CheckIncidents;
use App\Services\Screen\StatusScreenPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StatusScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_screens_create_shows_the_link_once_and_keeps_only_a_hash(): void
    {
        config(['app.url' => 'https://herdbook.example.ts.net']);

        $this->artisan('screens:create', ['name' => 'Lobby'])
            ->expectsOutputToContain('https://herdbook.example.ts.net/screen#')
            ->assertSuccessful();

        $screen = StatusScreen::query()->sole();
        $this->assertSame('Lobby', $screen->name);
        $this->assertSame(64, strlen($screen->token_hash));
        $this->artisan('screens:create', ['name' => 'Lobby'])->assertFailed();
    }

    public function test_the_page_holds_no_data_needs_no_login_and_is_never_cached(): void
    {
        $project = $this->projectOnScreen('Geheim Project');

        $response = $this->get('/screen');

        $response->assertSuccessful()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee($project->name)
            ->assertSee('\/api\/screen', false);
        $this->assertNull($response->headers->getCookies()[0] ?? null, 'The screen starts no session.');
    }

    public function test_without_a_good_token_the_api_says_no(): void
    {
        [$screen, $token] = StatusScreen::issue('Lobby');

        $this->getJson('/api/screen')->assertUnauthorized()->assertJson(['message' => 'Deze code is onbekend of ingetrokken.']);
        $this->getJson('/api/screen', ['Authorization' => 'Bearer verkeerd'])->assertUnauthorized();
        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"])->assertSuccessful();

        $this->artisan('screens:revoke', ['name' => 'Lobby'])->assertSuccessful();

        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->assertModelMissing($screen);
    }

    public function test_wrong_tokens_are_limited_without_locking_out_a_good_one(): void
    {
        [, $token] = StatusScreen::issue('Lobby');

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->getJson('/api/screen', ['Authorization' => "Bearer fout-{$attempt}"])->assertUnauthorized();
        }

        $this->getJson('/api/screen', ['Authorization' => 'Bearer nog-een'])->assertTooManyRequests();
        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"])->assertSuccessful();
    }

    public function test_a_screen_is_seen_at_most_once_a_minute(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        [$screen, $token] = StatusScreen::issue('Lobby');

        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"]);
        $this->assertSame('2026-10-23 12:00:00', $screen->fresh()->last_seen_at->toDateTimeString());

        Date::setTestNow('2026-10-23 12:00:30');
        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"]);
        $this->assertSame('2026-10-23 12:00:00', $screen->fresh()->last_seen_at->toDateTimeString());

        Date::setTestNow('2026-10-23 12:01:30');
        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"]);
        $this->assertSame('2026-10-23 12:01:30', $screen->fresh()->last_seen_at->toDateTimeString());
    }

    public function test_it_shows_the_chosen_projects_by_name_with_their_state(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        $down = $this->projectOnScreen('Studbook', CheckState::Down);
        $this->projectOnScreen('Pasture Planner', CheckState::Up);
        Project::factory()->create(['name' => 'Not chosen']);
        $this->projectOnScreen('Oud', CheckState::Up)->update(['status' => 'archived']);
        Incident::factory()->create(['severity' => IncidentSeverity::Sev1, 'status' => IncidentStatus::Open, 'started_at' => '2026-10-23 11:50:00'])->projects()->attach($down);
        Incident::factory()->create(['status' => IncidentStatus::Monitoring])->projects()->attach($down);

        $json = $this->screen()->assertSuccessful()->json();

        $this->assertSame(['Pasture Planner', 'Studbook'], array_column($json['projects'], 'name'));
        $this->assertSame(['down' => 1, 'up' => 1], array_filter($json['summary']));
        $studbook = $json['projects'][1];
        $this->assertSame(['down', 'Storing', '2026-10-23T11:55:00Z'], [$studbook['state'], $studbook['label'], $studbook['since']]);
        $this->assertSame([['severity' => 'sev1', 'label' => 'SEV1 · kritiek', 'since' => '2026-10-23T11:50:00Z']], $studbook['incidents']);
        $this->assertCount(24, $studbook['hours']);
        $this->assertArrayHasKey('now', $json);
        $this->assertFalse($json['monitoring']['stalled']);
    }

    public function test_an_incident_on_a_production_server_shows_on_the_project(): void
    {
        $project = $this->projectOnScreen('Pasture Planner');
        $server = Server::factory()->create();
        EnvironmentServer::create(['environment_id' => $project->environments()->first()->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        Incident::factory()->create(['severity' => IncidentSeverity::Sev2])->servers()->attach($server);

        $this->assertSame('sev2', $this->screen()->json('projects.0.incidents.0.severity'));
    }

    public function test_no_hostname_address_or_check_name_reaches_the_screen(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        $project = $this->projectOnScreen('Pasture Planner', CheckState::Down);
        $check = Check::query()->sole();
        $server = Server::factory()->create(['name' => 'herd-app', 'public_ipv4' => '203.0.113.10']);
        EnvironmentServer::create(['environment_id' => $project->environments()->first()->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        $check->forceFill(['last_error' => 'HTTP 502 van 203.0.113.10'])->saveQuietly();

        // An incident opened by a check: its title names the check (and the hostname).
        $incident = Incident::factory()->create(['title' => CheckIncidents::class.' '.$check->name.' onbereikbaar', 'severity' => IncidentSeverity::Sev1]);
        $incident->projects()->attach($project);

        $body = $this->screen()->assertSuccessful()->getContent();

        foreach ([$check->hostname->fqdn, $check->name, $check->target, '203.0.113.10', 'herd-app', $incident->title] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_one_screen_asking_too_often_is_slowed_down(): void
    {
        [, $token] = StatusScreen::issue('Lobby');

        for ($request = 0; $request < 20; $request++) {
            $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"])->assertSuccessful();
        }

        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"])->assertTooManyRequests();
    }

    public function test_creating_and_revoking_a_screen_is_logged_without_its_token(): void
    {
        $this->artisan('screens:create', ['name' => 'Lobby'])->assertSuccessful();
        $this->artisan('screens:revoke', ['name' => 'Lobby'])->assertSuccessful();

        $logged = Activity::query()->where('subject_type', 'status_screen')->get();

        $this->assertSame(['created', 'deleted'], $logged->pluck('event')->all());
        $this->assertStringNotContainsString('token', $logged->toJson());
    }

    public function test_screens_list_and_revoking_an_unknown_screen(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        [, $token] = StatusScreen::issue('Lobby');
        StatusScreen::issue('Hall');
        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"]);

        $this->artisan('screens:list')
            ->expectsTable(['Name', 'Created', 'Last seen'], [['Hall', '2026-10-23 14:00', 'never'], ['Lobby', '2026-10-23 14:00', '2026-10-23 14:00']])
            ->assertSuccessful();
        $this->artisan('screens:revoke', ['name' => 'Attic'])->assertFailed();
    }

    public function test_another_default_language_is_another_page_version(): void
    {
        $dutch = StatusScreenPayload::version();

        app()->setLocale('en');

        $this->assertNotSame($dutch, StatusScreenPayload::version());
    }

    public function test_the_payload_carries_the_page_version_and_is_shared_but_now_is_not(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        $this->projectOnScreen('Studbook');

        $first = $this->screen()->json();
        $this->projectOnScreen('Later toegevoegd');
        Date::setTestNow('2026-10-23 12:00:10');
        $second = $this->screen()->json();

        $this->assertSame(StatusScreenPayload::version(), $first['version']);
        $this->assertSame($first['projects'], $second['projects'], 'Cached for a few seconds, shared by every screen.');
        $this->assertSame('2026-10-23T12:00:10Z', $second['now']);
    }

    public function test_the_payload_has_the_fields_the_page_relies_on_and_keys_tiles_by_project(): void
    {
        $one = $this->projectOnScreen('Website');
        $two = $this->projectOnScreen('Website');

        $json = $this->screen()->json();

        $this->assertSame([$one->id, $two->id], array_column($json['projects'], 'key'));
        $this->assertSame(['generated_at', 'timezone', 'monitoring', 'backup', 'summary', 'projects', 'version', 'now'], array_keys($json));
        $this->assertSame(['key', 'name', 'state', 'label', 'since', 'uptime', 'response_ms', 'hours', 'incidents'], array_keys($json['projects'][0]));
    }

    public function test_only_open_incidents_of_the_project_or_the_servers_it_needs_show(): void
    {
        $project = $this->projectOnScreen('Pasture Planner');
        $other = $this->projectOnScreen('Ander');
        $app = Server::factory()->create();
        $storage = Server::factory()->create();
        $staging = Server::factory()->create();
        ServerDependency::create(['server_id' => $app->id, 'depends_on_id' => $storage->id, 'kind' => 'NFS']);
        EnvironmentServer::create(['environment_id' => $project->environments()->first()->id, 'server_id' => $app->id, 'role' => ServerRole::App]);
        EnvironmentServer::create(['environment_id' => Environment::factory()->for($project)->staging()->create()->id, 'server_id' => $staging->id, 'role' => ServerRole::App]);

        Incident::factory()->create(['severity' => IncidentSeverity::Sev2])->servers()->attach($storage);
        Incident::factory()->create(['severity' => IncidentSeverity::Sev1])->servers()->attach($staging);
        Incident::factory()->create(['severity' => IncidentSeverity::Sev1])->projects()->attach($other);
        Incident::factory()->resolved()->create(['severity' => IncidentSeverity::Sev1])->projects()->attach($project);

        $projects = collect($this->screen()->json('projects'))->keyBy('name');

        $this->assertSame(['sev2'], array_column($projects['Pasture Planner']['incidents'], 'severity'));
        $this->assertSame(['sev1'], array_column($projects['Ander']['incidents'], 'severity'));
    }

    public function test_nothing_else_may_read_the_api_from_another_origin(): void
    {
        [, $token] = StatusScreen::issue('Lobby');

        $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}", 'Origin' => 'https://elders.example'])
            ->assertSuccessful()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_the_screen_route_belongs_to_the_screen_not_to_the_panel(): void
    {
        $this->get('/screen')->assertSuccessful()->assertSee('Herdbook')->assertDontSee('Inloggen');
    }

    private function screen(): TestResponse
    {
        [, $token] = StatusScreen::issue('Test '.uniqid());

        return $this->getJson('/api/screen', ['Authorization' => "Bearer {$token}"]);
    }

    public function test_a_project_that_needs_no_checks_says_so_on_the_screen(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        $this->projectOnScreen('Studbook', CheckState::Up);
        $this->projectOnScreen('Office wiki')->update(['unmonitored' => true]);

        $json = $this->screen()->assertSuccessful()->json();

        $this->assertSame(['up' => 1, 'unmonitored' => 1], array_filter($json['summary']));
        $this->assertSame(['unmonitored', 'Bewaking niet nodig'], [$json['projects'][0]['state'], $json['projects'][0]['label']]);
    }

    private function projectOnScreen(string $name, ?CheckState $state = null): Project
    {
        $project = Project::factory()->create(['name' => $name, 'on_status_screen' => true]);
        $environment = Environment::factory()->for($project)->create();

        if ($state !== null) {
            $check = Check::factory()->for(Hostname::factory()->create(['environment_id' => $environment->id]))->create();
            $check->forceFill(['state' => $state, 'last_checked_at' => now(), 'last_state_changed_at' => now()->subMinutes(5)])->saveQuietly();
            $check->results()->create(['checked_at' => now()->subMinute(), 'ok' => $state === CheckState::Up]);
        }

        return $project;
    }
}
