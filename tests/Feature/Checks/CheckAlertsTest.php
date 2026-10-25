<?php

namespace Tests\Feature\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\User;
use App\Services\Alerts\CheckAlerts;
use App\Services\Checks\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

class CheckAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['herdbook.alerts.pushover_token' => 'app-token', 'herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake();
        $this->app->instance(NetworkProbe::class, new FakeProbe);
    }

    private function event(AlertLevel $level, CheckState $to = CheckState::Down): CheckEvent
    {
        $check = Check::factory()->create(['alert_level' => $level]);

        return $check->events()->create([
            'from_state' => $to === CheckState::Down ? CheckState::Up : CheckState::Down,
            'to_state' => $to,
            'occurred_at' => now(),
            'error' => 'HTTP 503',
        ]);
    }

    public function test_a_critical_outage_goes_to_pushover_with_emergency_priority(): void
    {
        User::factory()->create(['pushover_user_key' => str_repeat('u', 30), 'receives_critical_alerts' => true]);
        User::factory()->create(['pushover_user_key' => str_repeat('x', 30), 'receives_critical_alerts' => false]);
        $event = $this->event(AlertLevel::Critical);

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.pushover.net/1/messages.json'
            && $request['user'] === str_repeat('u', 30)
            && (int) $request['priority'] === 2
            && str_starts_with($request['title'], 'Down: '));
        $this->assertNotNull($event->fresh()->notified_at);
    }

    public function test_a_recovery_goes_out_without_emergency_priority(): void
    {
        User::factory()->create(['pushover_user_key' => str_repeat('u', 30), 'receives_critical_alerts' => true]);
        $this->event(AlertLevel::Critical, CheckState::Up);

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => (string) ($request->data()['priority'] ?? '') === '0' && str_starts_with($request->data()['title'] ?? '', 'Weer up: '));
        // the emergency of the outage stops repeating
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'cancel_by_tag/check-'));
    }

    public function test_a_normal_outage_goes_to_ntfy(): void
    {
        $this->event(AlertLevel::Normal);

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ntfy.example/herdbook'
            && $request->header('Priority') === ['4']
            && str_starts_with($request->header('Title')[0], 'Down: ')
            // bare: no error details leave the host
            && ! str_contains($request->body(), 'HTTP 503'));
    }

    public function test_a_critical_outage_falls_back_to_ntfy_without_pushover_recipients(): void
    {
        $this->event(AlertLevel::Critical);

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ntfy.example/herdbook');
    }

    public function test_a_silent_check_sends_nothing_but_is_handled(): void
    {
        $event = $this->event(AlertLevel::Silent);

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNotNull($event->fresh()->notified_at);
    }

    public function test_many_events_at_once_become_one_message(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->event(AlertLevel::Normal);
        }

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->header('Title') === ['6 checks veranderd (6 down)']);
    }

    public function test_messages_are_in_the_active_language(): void
    {
        app()->setLocale('en');
        $this->event(AlertLevel::Normal, CheckState::Up);

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('Title')[0], 'Up again: '));

        foreach (range(1, 6) as $ignored) {
            $this->event(AlertLevel::Normal);
        }

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->header('Title') === ['6 checks changed (6 down)']);
    }

    public function test_events_are_sent_once(): void
    {
        $this->event(AlertLevel::Normal);

        $this->artisan('checks:notify')->assertSuccessful();
        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_without_any_channel_the_events_are_marked_and_logged(): void
    {
        config(['herdbook.alerts.pushover_token' => null, 'herdbook.alerts.ntfy_url' => null]);
        $event = $this->event(AlertLevel::Normal);

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNotNull($event->fresh()->notified_at);
    }

    public function test_a_recovery_also_ends_a_grouped_emergency(): void
    {
        config(['herdbook.alerts.pushover_token' => 'token']);
        User::factory()->create(['pushover_user_key' => str_repeat('u', 30), 'receives_critical_alerts' => true]);
        Http::fake();
        Check::factory()->critical()->create()->events()->create(['from_state' => CheckState::Down, 'to_state' => CheckState::Up, 'occurred_at' => now()]);

        app(CheckAlerts::class)->sendPending();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'cancel_by_tag/checks-group'));
    }

    public function test_pausing_a_down_critical_check_stops_its_emergency_alarm(): void
    {
        config(['herdbook.alerts.pushover_token' => 'token']);
        User::factory()->create(['pushover_user_key' => str_repeat('u', 30), 'receives_critical_alerts' => true]);
        Http::fake();
        $check = Check::factory()->critical()->create();
        $check->forceFill(['state' => CheckState::Down])->save();

        $check->update(['enabled' => false]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "cancel_by_tag/check-{$check->id}"));
    }

    public function test_a_down_and_its_recovery_still_pending_become_one_quiet_message(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake();
        $check = Check::factory()->create();
        $check->events()->create(['from_state' => CheckState::Up, 'to_state' => CheckState::Down, 'occurred_at' => '2026-10-22 10:00:00']);
        $check->events()->create(['from_state' => CheckState::Down, 'to_state' => CheckState::Up, 'occurred_at' => '2026-10-22 10:04:00']);

        app(CheckAlerts::class)->sendPending();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('Title')[0], 'Even down: ')
            && $request->header('Priority') === ['3']
            && $request->body() === '12:00–12:04');
    }

    public function test_pushover_texts_are_cut_to_its_limits(): void
    {
        config(['herdbook.alerts.pushover_token' => 'token', 'herdbook.checks.group_alerts_from' => 2]);
        User::factory()->create(['pushover_user_key' => str_repeat('u', 30), 'receives_critical_alerts' => true]);
        Http::fake();
        foreach (range(1, 40) as $i) {
            Check::factory()->create(['alert_level' => AlertLevel::Critical, 'name' => str_repeat("lange-checknaam-{$i}-", 3)])
                ->events()->create(['from_state' => CheckState::Up, 'to_state' => CheckState::Down, 'occurred_at' => now()]);
        }

        app(CheckAlerts::class)->sendPending();

        Http::assertSent(fn (Request $request): bool => mb_strlen($request['message']) <= 1024 && mb_strlen($request['title']) <= 250);
    }
}
