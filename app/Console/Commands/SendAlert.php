<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertChannels;
use App\Services\Alerts\AlertFailed;
use App\Services\Alerts\AlertMessage;
use Illuminate\Console\Command;

/**
 * An alert through the same channels as the checks. For trying out a setup
 * (`docker/compose.sh exec app php artisan alerts:send Test`, or `--via=mail`
 * to try one channel while another one comes first), and for the host's own
 * scripts:
 * docker/backup/notify-failure.sh reports a failed backup with it.
 */
class SendAlert extends Command
{
    protected $signature = 'alerts:send
        {title : The headline}
        {body? : The text below it}
        {--problem : Bad news: a higher priority on ntfy}
        {--critical : With --problem: an emergency on Pushover that repeats until acknowledged}
        {--via= : Only through this channel (pushover, ntfy or mail), with its error if it fails}';

    protected $description = 'Send an alert through the configured channels';

    public function handle(AlertChannels $channels): int
    {
        $message = new AlertMessage(
            title: (string) $this->argument('title'),
            body: (string) $this->argument('body'),
            problem: (bool) $this->option('problem'),
            critical: (bool) $this->option('critical'),
        );
        $via = $this->option('via');

        try {
            if ($via === null) {
                $channels->send($message);
            } else {
                $channel = $channels->named((string) $via);

                if ($channel === null || ! $channel->isConfigured()) {
                    $this->components->error($channel === null
                        ? "Unknown channel '{$via}': use pushover, ntfy or mail."
                        : "{$via} is not set up (docs/alerts.md#channels).");

                    return self::FAILURE;
                }

                $channel->send($message);
            }
        } catch (AlertFailed $failure) {
            $this->components->error("Not sent: {$failure->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info('Sent.');

        return self::SUCCESS;
    }
}
