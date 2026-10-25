<?php

namespace Tests\Feature\Health;

use App\Models\Check;
use App\Models\CheckStat;
use App\Services\Checks\CheckHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class CheckHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rolled_up_hours_come_from_the_stats_and_the_rest_from_the_raw_results(): void
    {
        Date::setTestNow('2026-10-23 12:03:00');
        $check = Check::factory()->create();

        // 10:00 is rolled up; its raw results (pruned or not) no longer count.
        CheckStat::create(['check_id' => $check->id, 'granularity' => CheckStat::HOUR, 'period_start' => '2026-10-23 10:00:00', 'up_count' => 58, 'down_count' => 2]);
        $check->results()->create(['checked_at' => '2026-10-23 10:30:00', 'ok' => false]);
        // 11:00 is not rolled up yet (checks:aggregate runs at :05), 12:00 is running.
        $check->results()->createMany([
            ['checked_at' => '2026-10-23 11:00:00', 'ok' => true],
            ['checked_at' => '2026-10-23 11:59:59', 'ok' => false],
            ['checked_at' => '2026-10-23 12:01:00', 'ok' => true],
        ]);

        $hours = app(CheckHistory::class)->hourly([$check->id], 24)[$check->id];

        $this->assertCount(24, $hours);
        $this->assertSame(['up' => 0, 'down' => 0], $hours[0]);
        $this->assertSame(['up' => 58, 'down' => 2], $hours[21]);
        $this->assertSame(['up' => 1, 'down' => 1], $hours[22]);
        $this->assertSame(['up' => 1, 'down' => 0], $hours[23]);
    }

    public function test_results_older_than_the_window_are_left_out(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        $check = Check::factory()->create();
        $check->results()->create(['checked_at' => '2026-10-22 12:59:59', 'ok' => false]);
        CheckStat::create(['check_id' => $check->id, 'granularity' => CheckStat::HOUR, 'period_start' => '2026-10-22 12:00:00', 'up_count' => 0, 'down_count' => 60]);

        $hours = app(CheckHistory::class)->hourly([$check->id], 24)[$check->id];

        $this->assertSame(0, collect($hours)->sum('down'));
    }

    public function test_no_checks_no_history(): void
    {
        $this->assertSame([], app(CheckHistory::class)->hourly([]));
    }
}
