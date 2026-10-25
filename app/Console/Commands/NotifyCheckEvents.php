<?php

namespace App\Console\Commands;

use App\Services\Alerts\CheckAlerts;
use Illuminate\Console\Command;

class NotifyCheckEvents extends Command
{
    protected $signature = 'checks:notify';

    protected $description = 'Send notifications for check events nobody has heard about yet';

    public function handle(CheckAlerts $alerts): int
    {
        $this->components->info($alerts->sendPending().' check events handled.');

        return self::SUCCESS;
    }
}
