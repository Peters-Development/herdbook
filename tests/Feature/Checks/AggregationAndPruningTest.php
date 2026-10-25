<?php

namespace Tests\Feature\Checks;

use App\Models\Check;
use App\Models\CheckResult;
use App\Models\CheckStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class AggregationAndPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_after_an_outage_of_the_scheduler_it_catches_up_from_the_last_rolled_up_hour(): void
    {
        $check = Check::factory()->create();
        CheckStat::create(['check_id' => $check->id, 'granularity' => 'hour', 'period_start' => '2026-10-22 04:00:00', 'up_count' => 60, 'down_count' => 0]);
        CheckResult::create(['check_id' => $check->id, 'checked_at' => '2026-10-22 05:30:00', 'ok' => true]);
        CheckResult::create(['check_id' => $check->id, 'checked_at' => '2026-10-22 06:30:00', 'ok' => false]);
        Date::setTestNow('2026-10-22 12:05:00');

        $this->artisan('checks:aggregate')->assertSuccessful();

        $this->assertTrue(CheckStat::query()->where('granularity', 'hour')->where('period_start', '2026-10-22 05:00:00')->exists());
        $this->assertSame(1, CheckStat::query()->where('granularity', 'hour')->where('period_start', '2026-10-22 06:00:00')->value('down_count'));
    }

    public function test_results_roll_up_into_hours_and_days_and_running_again_changes_nothing(): void
    {
        $check = Check::factory()->create();
        foreach ([['09:10', true, 100], ['09:20', true, 300], ['09:30', false, null], ['10:10', true, 200]] as [$time, $ok, $ms]) {
            CheckResult::create(['check_id' => $check->id, 'checked_at' => "2026-10-22 {$time}:00", 'ok' => $ok, 'response_ms' => $ms]);
        }
        Date::setTestNow('2026-10-22 11:05:00');

        $this->artisan('checks:aggregate')->assertSuccessful();
        $this->artisan('checks:aggregate')->assertSuccessful();

        $nine = CheckStat::query()->where('granularity', 'hour')->where('period_start', '2026-10-22 09:00:00')->sole();
        $this->assertSame([2, 1, 200, 100, 300], [$nine->up_count, $nine->down_count, $nine->avg_ms, $nine->min_ms, $nine->max_ms]);

        $day = CheckStat::query()->where('granularity', 'day')->sole();
        $this->assertSame([3, 1, 100, 300], [$day->up_count, $day->down_count, $day->min_ms, $day->max_ms]);
        $this->assertSame(3, CheckStat::query()->count());
    }

    public function test_old_results_and_hour_stats_are_pruned_but_days_stay(): void
    {
        Date::setTestNow('2026-10-22 12:00:00');
        $check = Check::factory()->create();
        CheckResult::create(['check_id' => $check->id, 'checked_at' => '2026-10-01 12:00:00', 'ok' => true]);
        CheckResult::create(['check_id' => $check->id, 'checked_at' => '2026-10-20 12:00:00', 'ok' => true]);
        CheckStat::create(['check_id' => $check->id, 'granularity' => 'hour', 'period_start' => '2026-06-01 12:00:00']);
        CheckStat::create(['check_id' => $check->id, 'granularity' => 'day', 'period_start' => '2026-06-01 00:00:00']);

        $this->artisan('model:prune', ['--model' => [CheckResult::class, CheckStat::class]])->assertSuccessful();

        $this->assertSame(['2026-10-20 12:00:00'], CheckResult::query()->pluck('checked_at')->map->toDateTimeString()->all());
        $this->assertSame(['day'], CheckStat::query()->pluck('granularity')->all());
    }
}
