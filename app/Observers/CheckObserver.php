<?php

namespace App\Observers;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Models\Check;
use App\Services\Alerts\CheckAlerts;
use App\Services\Alerts\PushoverChannel;

/**
 * A check that is paused or deleted while its emergency alarm repeats (a
 * critical check down) stops that alarm: pausing a dead server's checks must
 * not leave the phone ringing for an hour.
 */
class CheckObserver
{
    public function __construct(private readonly PushoverChannel $pushover) {}

    public function updated(Check $check): void
    {
        if ($check->wasChanged('enabled') && ! $check->enabled) {
            $this->cancelEmergency($check);
        }
    }

    public function deleted(Check $check): void
    {
        $this->cancelEmergency($check);
    }

    private function cancelEmergency(Check $check): void
    {
        if ($check->state === CheckState::Down && $check->alert_level === AlertLevel::Critical && $this->pushover->isConfigured()) {
            $this->pushover->cancel(CheckAlerts::tag($check->id));
        }
    }
}
