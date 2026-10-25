<?php

namespace App\Console\Commands;

use App\Models\StatusScreen;
use Illuminate\Console\Command;

/**
 * Takes a display's link away: its next request gets a 401 and the screen
 * says "Access revoked".
 */
class RevokeStatusScreen extends Command
{
    protected $signature = 'screens:revoke {name : The name given at screens:create}';

    protected $description = 'Revoke the link of a status screen';

    public function handle(): int
    {
        $screen = StatusScreen::query()->where('name', trim((string) $this->argument('name')))->first();

        if ($screen === null) {
            $this->components->error('No status screen with that name. See screens:list.');

            return self::FAILURE;
        }

        $screen->delete();

        $this->components->info("Status screen \"{$screen->name}\" revoked.");

        return self::SUCCESS;
    }
}
