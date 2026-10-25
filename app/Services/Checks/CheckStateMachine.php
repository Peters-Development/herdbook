<?php

namespace App\Services\Checks;

use App\Enums\CheckState;
use App\Models\Check;
use App\Models\CheckEvent;
use Carbon\CarbonImmutable;

/**
 * Turns results into a state, with thresholds against flapping:
 *
 *   up/unknown --1st failure--> pending --failure_threshold in a row--> down
 *   down --success_threshold in a row--> up
 *
 * Only a switch between up and down is an event (notifications, incidents).
 */
final class CheckStateMachine
{
    /**
     * The counters stop here: a check that is up for months must not outgrow
     * its column, and no threshold is anywhere near it.
     */
    public const int MAX_COUNT = 1000;

    public function apply(Check $check, CheckOutcome $outcome, CarbonImmutable $at): ?CheckEvent
    {
        $previous = $check->state;

        $check->forceFill([
            'last_checked_at' => $at,
            'last_response_ms' => $outcome->responseMs,
            'last_error' => $outcome->error,
            'consecutive_failures' => $outcome->ok ? 0 : min($check->consecutive_failures + 1, self::MAX_COUNT),
            'consecutive_successes' => $outcome->ok ? min($check->consecutive_successes + 1, self::MAX_COUNT) : 0,
        ]);

        $next = $outcome->ok ? $this->afterSuccess($check, $previous) : $this->afterFailure($check, $previous);

        if ($next !== $previous) {
            $check->forceFill(['state' => $next, 'last_state_changed_at' => $at]);
        }

        $check->save();

        if (! $this->isSwitch($previous, $next)) {
            return null;
        }

        return $check->events()->create([
            'from_state' => $previous,
            'to_state' => $next,
            'occurred_at' => $at,
            'error' => $outcome->error,
        ]);
    }

    private function afterSuccess(Check $check, CheckState $previous): CheckState
    {
        if ($previous === CheckState::Down) {
            return $check->consecutive_successes >= $check->success_threshold ? CheckState::Up : CheckState::Down;
        }

        return CheckState::Up;
    }

    private function afterFailure(Check $check, CheckState $previous): CheckState
    {
        if ($check->consecutive_failures >= $check->failure_threshold) {
            return CheckState::Down;
        }

        return $previous === CheckState::Down ? CheckState::Down : CheckState::Pending;
    }

    /**
     * Up to down and down to up count; a first result (unknown) going down
     * counts too, unknown to up does not.
     */
    private function isSwitch(CheckState $previous, CheckState $next): bool
    {
        return ($next === CheckState::Down && $previous !== CheckState::Down)
            || ($next === CheckState::Up && $previous === CheckState::Down);
    }
}
