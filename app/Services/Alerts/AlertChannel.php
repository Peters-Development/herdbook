<?php

namespace App\Services\Alerts;

/**
 * A way out for alerts: Pushover, ntfy or email. AlertChannels picks the
 * order; a channel only says whether it is set up and delivers.
 */
interface AlertChannel
{
    public function isConfigured(): bool;

    /**
     * @throws AlertFailed when the message did not get out
     */
    public function send(AlertMessage $message): void;
}
