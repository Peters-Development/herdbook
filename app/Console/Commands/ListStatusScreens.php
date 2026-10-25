<?php

namespace App\Console\Commands;

use App\Models\StatusScreen;
use Illuminate\Console\Command;

/**
 * Which displays may read the status screen, and when each last asked: a
 * screen that went quiet is off, or lost its network.
 */
class ListStatusScreens extends Command
{
    protected $signature = 'screens:list';

    protected $description = 'List the status screens and when they were last seen';

    public function handle(): int
    {
        $screens = StatusScreen::query()->orderBy('name')->get();

        if ($screens->isEmpty()) {
            $this->components->info('No status screens yet. Create one with screens:create.');

            return self::SUCCESS;
        }

        $timezone = config('herdbook.display_timezone');

        $this->table(['Name', 'Created', 'Last seen'], $screens->map(fn (StatusScreen $screen): array => [
            $screen->name,
            $screen->created_at?->timezone($timezone)->format('Y-m-d H:i'),
            $screen->last_seen_at?->timezone($timezone)->format('Y-m-d H:i') ?? 'never',
        ]));

        return self::SUCCESS;
    }
}
