<?php

namespace App\Console\Commands;

use App\Services\Checks\MonitoringPulse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Every five minutes: "this host is alive and its checks run", to whatever
 * watches it (herdbook.heartbeat_url). Not sent while there are active checks
 * but no fresh result: a stuck queue or a broken deploy must turn the
 * monitor red too, not only a dead host. Does nothing when no URL is set.
 */
class SendHeartbeat extends Command
{
    protected $signature = 'heartbeat';

    protected $description = 'Ping the heartbeat URL while checks keep running';

    public function handle(MonitoringPulse $pulse): int
    {
        $url = config('herdbook.heartbeat_url');

        if (blank($url)) {
            return self::SUCCESS;
        }

        if ($pulse->isStalled()) {
            $this->components->warn('No fresh check results: heartbeat withheld.');

            return self::FAILURE;
        }

        try {
            Http::timeout(10)->get($url)->throw();
        } catch (Throwable $exception) {
            $this->components->error("Heartbeat failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
