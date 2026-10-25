<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The panel has no registration: every account starts here. Two-factor
 * authentication is set up on first sign-in (the panel requires it).
 */
class CreateUser extends Command
{
    protected $signature = 'users:create
        {--name= : Display name}
        {--email= : Email address used to sign in}';

    protected $description = 'Create a panel account (there is no registration)';

    public function handle(): int
    {
        $attributes = [
            'name' => $this->option('name') ?? text('Name', required: true),
            'email' => $this->option('email') ?? text('Email', required: true),
            'password' => password('Password (min. 12 characters)', required: true),
            'password_confirmation' => password('Confirm password', required: true),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create($validator->safe()->only(['name', 'email', 'password']));

        $this->components->info("User {$user->email} created. Two-factor authentication is set up on first sign-in.");

        return self::SUCCESS;
    }
}
