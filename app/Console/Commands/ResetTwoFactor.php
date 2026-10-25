<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The way back for an account that lost both its authenticator app and its
 * recovery codes. The panel then asks for a new setup at the next sign-in.
 *
 * Every session and remember-me cookie of the account is revoked too: the
 * setup page asks for no password, so a lost phone that is still signed in
 * must not be the device that sets up the new authenticator.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'users:reset-two-factor {email : Email address of the account}';

    protected $description = 'Remove the two-factor setup of an account and sign it out everywhere';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No account with that email address.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user): void {
            $user->app_authentication_secret = null;
            $user->app_authentication_recovery_codes = null;
            $user->setRememberToken(Str::random(60));
            $user->save();

            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        });

        $this->components->info("Two-factor authentication of {$user->email} removed and all its sessions signed out. It is set up again at the next sign-in.");

        return self::SUCCESS;
    }
}
