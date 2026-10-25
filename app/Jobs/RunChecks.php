<?php

namespace App\Jobs;

use App\Enums\CheckState;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\CheckResult;
use App\Services\Checks\CheckIncidents;
use App\Services\Checks\CheckOutcome;
use App\Services\Checks\CheckRunner;
use App\Services\Checks\CheckStateMachine;
use App\Services\Checks\ConnectivityGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Runs a batch of checks (DispatchChecks makes the batches) and records each
 * result as soon as it is in: a batch cut off by its timeout loses only the
 * checks after it. Nothing is recorded while the host's own line is down.
 */
class RunChecks implements ShouldQueue
{
    use Localizable, Queueable;

    /**
     * Below the worker's --timeout (60): a batch that hangs is cut off before
     * the queue hands it out again.
     */
    public int $timeout = 50;

    public int $tries = 1;

    /**
     * Declared with a default, not promoted: a queued job still deserialises
     * after the constructor changes.
     *
     * @var list<int>
     */
    public array $checkIds = [];

    /**
     * "Check now": run even when the check ran a moment ago.
     */
    public bool $force = false;

    /**
     * Once the own line answered during this run, later failures in the same
     * run do not ask again.
     */
    private bool $lineConfirmed = false;

    /**
     * @param  list<int>  $checkIds
     */
    public function __construct(array $checkIds, bool $force = false)
    {
        $this->checkIds = $checkIds;
        $this->force = $force;
        $this->onQueue('checks');
    }

    public function handle(CheckRunner $runner, ConnectivityGuard $guard, CheckStateMachine $stateMachine, CheckIncidents $incidents): void
    {
        // What it stores (errors, timeline notes) is in the default language,
        // also when someone runs it from the panel ("Check now").
        $this->withLocale(config('herdbook.default_locale'), fn () => $this->run($runner, $guard, $stateMachine, $incidents));
    }

    private function run(CheckRunner $runner, ConnectivityGuard $guard, CheckStateMachine $stateMachine, CheckIncidents $incidents): void
    {
        $checks = Check::query()
            ->whereKey($this->checkIds)
            ->where('enabled', true)
            ->with(['hostname.environment', 'server'])
            ->get();

        [$http, $other] = $checks->partition(fn (Check $check): bool => $check->type->isHttp());

        foreach ($runner->runHttp($http) as $checkId => $outcome) {
            if (! $this->record($checkId, $outcome, $guard, $stateMachine, $incidents)) {
                return;
            }
        }

        foreach ($other as $check) {
            if (! $this->record($check->id, $runner->runOne($check), $guard, $stateMachine, $incidents)) {
                return;
            }
        }
    }

    /**
     * @return bool false when the own line is down: stop, record nothing more
     */
    private function record(int $checkId, CheckOutcome $outcome, ConnectivityGuard $guard, CheckStateMachine $stateMachine, CheckIncidents $incidents): bool
    {
        if (! $outcome->ok && ! $this->lineConfirmed) {
            if (! $guard->isOnline()) {
                return false;
            }

            $this->lineConfirmed = true;
        }

        $event = DB::transaction(function () use ($checkId, $outcome, $stateMachine): ?CheckEvent {
            // Re-read under a lock: "Check now" may run the same check at
            // the same moment, and both must not decide on a stale state.
            $check = Check::query()->lockForUpdate()->find($checkId);

            if ($check === null || ! $check->enabled) {
                return null;
            }

            $at = Date::now();

            // A job that waited in a backlog behind another one for the same
            // check: three results in a few seconds must not count as three
            // failures in a row.
            if (! $this->force && $check->last_checked_at?->gt($at->subSeconds(intdiv($check->effectiveInterval(), 2)))) {
                return null;
            }

            CheckResult::create([
                'check_id' => $check->id,
                'checked_at' => $at,
                'ok' => $outcome->ok,
                'response_ms' => $outcome->responseMs,
                'status_code' => $outcome->statusCode,
                'error' => $outcome->error,
            ]);

            return $stateMachine->apply($check, $outcome, $at);
        });

        if ($event?->to_state === CheckState::Up) {
            $incidents->recovered($event);
        }

        return true;
    }
}
