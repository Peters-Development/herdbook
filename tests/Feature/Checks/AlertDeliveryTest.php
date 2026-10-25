<?php

namespace Tests\Feature\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Models\Check;
use App\Models\User;
use App\Services\Alerts\CheckAlerts;
use App\Services\Checks\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

class AlertDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, $this->probe = new FakeProbe);
    }

    public function test_a_failed_send_is_retried_and_after_hours_marked_as_failed(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake(['ntfy.example/*' => Http::response('', 500)]);
        Date::setTestNow('2026-10-22 10:00:00');
        $check = Check::factory()->create(['alert_level' => AlertLevel::Normal]);
        $event = $check->events()->create(['from_state' => CheckState::Up, 'to_state' => CheckState::Down, 'occurred_at' => now()]);

        app(CheckAlerts::class)->sendPending();
        app(CheckAlerts::class)->sendPending();

        $this->assertNull($event->fresh()->notified_at);
        $this->assertSame(2, $event->fresh()->notify_attempts);

        Date::setTestNow('2026-10-22 16:01:00');
        app(CheckAlerts::class)->sendPending();

        $this->assertNotNull($event->fresh()->notified_at);
        $this->assertTrue($event->fresh()->notify_failed);
    }

    public function test_nothing_is_counted_while_the_own_line_is_down(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake(['ntfy.example/*' => fn () => throw new ConnectionException('Could not resolve host')]);
        $this->probe->online = false;
        $event = Check::factory()->create()->events()->create(['from_state' => CheckState::Up, 'to_state' => CheckState::Down, 'occurred_at' => now()]);

        app(CheckAlerts::class)->sendPending();

        $this->assertSame(0, $event->fresh()->notify_attempts);
        $this->assertNull($event->fresh()->notified_at);
    }

    public function test_one_channel_failing_falls_back_to_the_other(): void
    {
        config(['herdbook.alerts.pushover_token' => 'token', 'herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        User::factory()->create(['pushover_user_key' => str_repeat('u', 30), 'receives_critical_alerts' => true]);
        Http::fake([
            'api.pushover.net/*' => Http::response(['status' => 0], 400),
            'ntfy.example/*' => Http::response('ok'),
        ]);
        $event = Check::factory()->critical()->create()->events()->create(['from_state' => CheckState::Up, 'to_state' => CheckState::Down, 'occurred_at' => now()]);

        app(CheckAlerts::class)->sendPending();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ntfy.example') && $request->header('Priority') === ['5']);
        $this->assertNotNull($event->fresh()->notified_at);
        $this->assertFalse($event->fresh()->notify_failed);
    }
}
