<?php

namespace App\Console\Commands;

use App\Services\Checks\CheckIncidents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class OpenCheckIncidents extends Command
{
    protected $signature = 'checks:incidents';

    protected $description = 'Open draft incidents for critical production checks that have been down for a while';

    public function handle(CheckIncidents $incidents): int
    {
        $this->components->info($incidents->openForLongOutages(Date::now()).' incidents opened.');

        return self::SUCCESS;
    }
}
