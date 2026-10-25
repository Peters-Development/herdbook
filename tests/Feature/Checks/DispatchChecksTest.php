<?php

namespace Tests\Feature\Checks;

use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Jobs\RunChecks;
use App\Models\Check;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_checks_are_queued_in_batches_and_moved_forward(): void
    {
        Queue::fake();
        Date::setTestNow('2026-10-22 10:00:00');
        $http = Check::factory()->count(25)->create();
        $tls = Check::factory()->count(5)->ofType(CheckType::Tls, 'app.example.net:443')->create(['interval_seconds' => 21600]);
        $tls->each(fn (Check $check) => $check->forceFill(['state' => CheckState::Up])->save());

        $this->artisan('checks:dispatch')->assertSuccessful();

        // 25 HTTP checks: batches of 20 and 5; 5 others: batches of 2, 2 and 1
        Queue::assertPushed(RunChecks::class, 5);
        // not up yet (unknown): tried again within five minutes, here its own minute
        $this->assertSame('2026-10-22 10:01:00', $http->first()->fresh()->next_check_at->toDateTimeString());
        $this->assertSame('2026-10-22 16:00:00', $tls->first()->fresh()->next_check_at->toDateTimeString());
    }

    public function test_a_long_interval_check_that_is_not_up_is_retried_within_five_minutes(): void
    {
        Queue::fake();
        Date::setTestNow('2026-10-22 10:00:00');
        $tls = Check::factory()->ofType(CheckType::Tls, 'app.example.net:443')->create(['interval_seconds' => 21600]);
        $tls->forceFill(['state' => CheckState::Pending])->save();

        $this->artisan('checks:dispatch')->assertSuccessful();

        $this->assertSame('2026-10-22 10:05:00', $tls->fresh()->next_check_at->toDateTimeString());
    }

    public function test_checks_that_are_not_due_or_paused_are_left_alone(): void
    {
        Queue::fake();
        Date::setTestNow('2026-10-22 10:00:00');
        Check::factory()->create(['enabled' => false]);
        $later = Check::factory()->create();
        $later->forceFill(['next_check_at' => '2026-10-22 10:00:30'])->save();

        $this->artisan('checks:dispatch')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_changed_check_runs_at_the_next_dispatch(): void
    {
        $check = Check::factory()->create();
        $check->forceFill(['next_check_at' => now()->addHour()])->save();

        $check->update(['target' => 'https://other.example.net/']);

        $this->assertNull($check->fresh()->next_check_at);
    }

    public function test_the_next_run_counts_from_the_start_of_the_minute(): void
    {
        Queue::fake();
        Date::setTestNow(Date::parse('2026-10-22 10:00:00.700'));
        $check = Check::factory()->create();
        $check->forceFill(['state' => CheckState::Up])->save();

        $this->artisan('checks:dispatch')->assertSuccessful();

        $this->assertSame('2026-10-22 10:01:00', $check->fresh()->next_check_at->toDateTimeString());
    }
}
