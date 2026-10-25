<?php

namespace Tests\Feature\Console;

use App\Models\Check;
use App\Models\CheckResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatchTheWatcherTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = sys_get_temp_dir().'/herdbook-backup-marker-'.uniqid();
        config(['herdbook.backup_marker' => $this->marker, 'herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);

        parent::tearDown();
    }

    public function test_the_heartbeat_pings_only_when_configured(): void
    {
        $this->artisan('heartbeat')->assertSuccessful();
        Http::assertNothingSent();

        config(['herdbook.heartbeat_url' => 'https://hc.example/ping/abc']);
        $this->artisan('heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hc.example/ping/abc');
    }

    public function test_without_a_marker_backups_are_not_set_up_and_nothing_is_said(): void
    {
        $this->artisan('backups:check')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_recent_backup_is_fine(): void
    {
        touch($this->marker, now()->subHours(20)->getTimestamp());

        $this->artisan('backups:check')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_an_old_backup_is_reported(): void
    {
        touch($this->marker, now()->subHours(50)->getTimestamp());

        $this->artisan('backups:check')->assertFailed();

        Http::assertSent(fn (Request $request): bool => $request->header('Title') === ['Backup is verouderd']);
    }

    public function test_the_schedule_holds_every_task(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('checks:dispatch')
            ->expectsOutputToContain('backups:check')
            ->expectsOutputToContain('heartbeat')
            ->expectsOutputToContain('renewals:digest')
            ->assertSuccessful();
    }

    public function test_the_heartbeat_is_withheld_while_checks_do_not_run(): void
    {
        config(['herdbook.heartbeat_url' => 'https://hc.example/ping/abc']);
        Http::fake();
        $check = Check::factory()->create();
        CheckResult::create(['check_id' => $check->id, 'checked_at' => now()->subMinutes(20), 'ok' => true]);

        $this->artisan('heartbeat')->assertFailed();
        Http::assertNothingSent();

        CheckResult::create(['check_id' => $check->id, 'checked_at' => now()->subMinute(), 'ok' => true]);
        $this->artisan('heartbeat')->assertSuccessful();
        Http::assertSentCount(1);
    }
}
