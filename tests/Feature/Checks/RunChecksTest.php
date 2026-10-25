<?php

namespace Tests\Feature\Checks;

use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Enums\IncidentStatus;
use App\Jobs\RunChecks;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Services\Checks\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

class RunChecksTest extends TestCase
{
    use RefreshDatabase;

    private FakeProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, $this->probe = new FakeProbe);
    }

    public function test_results_and_states_are_recorded(): void
    {
        Http::fake(['up.example.net/*' => Http::response('ok'), 'down.example.net/*' => Http::response('', 500)]);
        $up = Check::factory()->ofType(CheckType::Http, 'https://up.example.net/')->create();
        $down = Check::factory()->ofType(CheckType::Http, 'https://down.example.net/')->create(['failure_threshold' => 1]);

        RunChecks::dispatchSync([$up->id, $down->id]);

        $this->assertSame(CheckState::Up, $up->fresh()->state);
        $this->assertSame(CheckState::Down, $down->fresh()->state);
        $this->assertSame(2, CheckResult::count());
        $this->assertSame(1, $down->events()->count());
    }

    public function test_what_it_stores_is_in_the_default_language_whoever_runs_it(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $check = Check::factory()->ofType(CheckType::Http, 'https://shop.example.com/')->create(['failure_threshold' => 1, 'settings' => ['expected_status' => 200]]);
        // Someone using English presses "Check now"; the default is Dutch.
        app()->setLocale('en');

        RunChecks::dispatchSync([$check->id]);

        $this->assertSame('HTTP 503, verwacht 200', $check->fresh()->last_error);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_nothing_is_recorded_when_the_own_line_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
        $this->probe->online = false;
        $check = Check::factory()->create(['failure_threshold' => 1]);

        RunChecks::dispatchSync([$check->id]);

        $this->assertSame(0, CheckResult::count());
        $this->assertSame(CheckState::Unknown, $check->fresh()->state);
        $this->assertContains('https://one.one.one.one/cdn-cgi/trace', $this->probe->reachabilityChecks);
    }

    public function test_a_paused_check_is_skipped(): void
    {
        Http::fake();
        $check = Check::factory()->create(['enabled' => false]);

        RunChecks::dispatchSync([$check->id]);

        $this->assertSame(0, CheckResult::count());
        Http::assertNothingSent();
    }

    public function test_a_recovery_is_added_to_the_incident_of_the_outage(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $check = Check::factory()->create(['failure_threshold' => 1, 'success_threshold' => 1]);
        $check->forceFill(['state' => CheckState::Down])->save();
        $incident = Incident::factory()->create(['status' => IncidentStatus::Open]);
        $check->events()->create(['from_state' => CheckState::Up, 'to_state' => CheckState::Down, 'occurred_at' => now()->subMinutes(10), 'incident_id' => $incident->id]);

        RunChecks::dispatchSync([$check->id]);

        $this->assertSame(IncidentStatus::Monitoring, $incident->fresh()->status);
        $this->assertStringStartsWith('Weer up:', $incident->timelineEntries()->sole()->note);
    }

    public function test_the_job_runs_on_the_checks_queue(): void
    {
        Queue::fake();

        RunChecks::dispatch([1]);

        Queue::assertPushedOn('checks', RunChecks::class);
    }

    public function test_a_job_that_waited_behind_another_for_the_same_check_records_nothing(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Date::setTestNow('2026-10-22 10:00:30');
        $check = Check::factory()->create();
        $check->forceFill(['last_checked_at' => '2026-10-22 10:00:10', 'state' => CheckState::Up])->save();

        RunChecks::dispatchSync([$check->id]);
        $this->assertSame(0, CheckResult::count());

        RunChecks::dispatchSync([$check->id], force: true);
        $this->assertSame(1, CheckResult::count());
    }
}
