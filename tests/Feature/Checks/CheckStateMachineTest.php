<?php

namespace Tests\Feature\Checks;

use App\Enums\CheckState;
use App\Models\Check;
use App\Services\Checks\CheckOutcome;
use App\Services\Checks\CheckStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class CheckStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function apply(Check $check, bool $ok): ?CheckState
    {
        $event = app(CheckStateMachine::class)->apply(
            $check,
            $ok ? CheckOutcome::up(100) : CheckOutcome::down('HTTP 503'),
            Date::now(),
        );

        return $event?->to_state;
    }

    public function test_a_first_success_is_up_without_an_event(): void
    {
        $check = Check::factory()->create();

        $this->assertNull($this->apply($check, true));
        $this->assertSame(CheckState::Up, $check->state);
    }

    public function test_it_goes_down_only_after_the_failure_threshold(): void
    {
        $check = Check::factory()->create(['failure_threshold' => 3]);
        $this->apply($check, true);

        $this->assertNull($this->apply($check, false));
        $this->assertSame(CheckState::Pending, $check->state);
        $this->assertNull($this->apply($check, false));
        $this->assertSame(CheckState::Down, $this->apply($check, false));
        $this->assertSame(CheckState::Down, $check->state);
        $this->assertSame('HTTP 503', $check->last_error);
    }

    public function test_one_good_result_in_between_resets_the_count(): void
    {
        $check = Check::factory()->create(['failure_threshold' => 3]);

        $this->apply($check, false);
        $this->apply($check, false);
        $this->apply($check, true);
        $this->apply($check, false);

        $this->assertSame(CheckState::Pending, $check->state);
    }

    public function test_it_comes_back_up_only_after_the_success_threshold(): void
    {
        $check = Check::factory()->create(['failure_threshold' => 1, 'success_threshold' => 2]);
        $this->apply($check, false);

        $this->assertNull($this->apply($check, true));
        $this->assertSame(CheckState::Down, $check->state);
        $this->assertSame(CheckState::Up, $this->apply($check, true));
        $this->assertSame(CheckState::Up, $check->state);
    }

    public function test_more_failures_while_down_make_no_new_event(): void
    {
        $check = Check::factory()->create(['failure_threshold' => 1]);

        $this->assertSame(CheckState::Down, $this->apply($check, false));
        $this->assertNull($this->apply($check, false));
        $this->assertSame(1, $check->events()->count());
    }

    public function test_a_threshold_of_one_goes_down_at_once(): void
    {
        $check = Check::factory()->create(['failure_threshold' => 1]);

        $this->assertSame(CheckState::Down, $this->apply($check, false));
    }

    public function test_the_last_check_and_the_state_change_are_recorded(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');
        $check = Check::factory()->create(['failure_threshold' => 1]);

        $this->apply($check, false);

        $check->refresh();
        $this->assertSame('2026-10-22 10:00:00', $check->last_checked_at->toDateTimeString());
        $this->assertSame('2026-10-22 10:00:00', $check->last_state_changed_at->toDateTimeString());
        $this->assertSame(1, $check->consecutive_failures);
    }

    public function test_the_counters_stop_at_a_cap(): void
    {
        $check = Check::factory()->create();
        $check->forceFill(['state' => CheckState::Up, 'consecutive_successes' => CheckStateMachine::MAX_COUNT])->save();

        app(CheckStateMachine::class)->apply($check, CheckOutcome::up(), Date::now());

        $this->assertSame(CheckStateMachine::MAX_COUNT, $check->fresh()->consecutive_successes);
    }
}
