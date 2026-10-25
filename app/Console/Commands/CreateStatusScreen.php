<?php

namespace App\Console\Commands;

use App\Models\StatusScreen;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\text;

/**
 * A link for a wall display (/screen#<token>). The token is shown
 * once; the database only keeps its hash.
 */
class CreateStatusScreen extends Command
{
    protected $signature = 'screens:create {name? : What the display is called, e.g. "Hallway"}';

    protected $description = 'Create a link for a status screen (the token is shown once)';

    public function handle(): int
    {
        $name = trim((string) ($this->argument('name') ?? text('Name of the display', placeholder: 'Hallway', required: true)));

        $validator = Validator::make(['name' => $name], [
            'name' => ['required', 'string', 'max:255', Rule::unique(StatusScreen::class)],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        [, $token] = StatusScreen::issue($name);

        $this->components->info("Status screen \"{$name}\" created. Open this link on the display (shown only now):");
        $this->line('  '.rtrim((string) config('app.url'), '/').'/screen#'.$token);
        $this->newLine();
        $this->components->warn('Anyone with this link sees the status screen. Revoke it with: screens:revoke "'.$name.'"');

        return self::SUCCESS;
    }
}
