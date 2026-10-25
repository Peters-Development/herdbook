<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertChannels;
use App\Services\Alerts\AlertFailed;
use App\Services\Alerts\AlertMessage;
use App\Services\BackupFreshness;
use Illuminate\Console\Command;

/**
 * Daily: is the last good backup recent (BackupFreshness)?
 */
class CheckBackupFreshness extends Command
{
    protected $signature = 'backups:check';

    protected $description = 'Warn when the last successful backup is too old';

    public function handle(AlertChannels $channels, BackupFreshness $backups): int
    {
        $last = $backups->lastSuccess();

        if ($last === null) {
            $this->components->info('No backup marker: backups are not set up yet.');

            return self::SUCCESS;
        }

        $hours = (int) $last->diffInHours(now());

        if (! $backups->isStale()) {
            $this->components->info("Last backup {$hours} hours ago.");

            return self::SUCCESS;
        }

        $message = new AlertMessage(
            title: __('Backup is out of date'),
            body: __('Last successful backup: :time', ['time' => $last->timezone(config('herdbook.display_timezone'))->translatedFormat(__('formats.datetime'))]),
            problem: true,
        );

        try {
            $channels->send($message);
        } catch (AlertFailed $failure) {
            $this->components->warn("Warning not sent: {$failure->getMessage()}");
        }

        $this->components->error("Last successful backup is {$hours} hours old.");

        return self::FAILURE;
    }
}
