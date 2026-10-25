<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-battery';

    public function test_it_creates_a_user_with_a_hashed_password(): void
    {
        $this->artisan('users:create', ['--name' => 'Ada', '--email' => 'ada@example.com'])
            ->expectsQuestion('Password (min. 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();

        $user = User::where('email', 'ada@example.com')->sole();

        $this->assertSame('Ada', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNull($user->getAppAuthenticationSecret());
    }

    public function test_it_refuses_an_e_mail_address_that_is_already_taken(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('users:create', ['--name' => 'Ada', '--email' => 'ada@example.com'])
            ->expectsQuestion('Password (min. 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_it_refuses_a_password_shorter_than_twelve_characters(): void
    {
        $this->artisan('users:create', ['--name' => 'Ada', '--email' => 'ada@example.com'])
            ->expectsQuestion('Password (min. 12 characters)', 'too-short')
            ->expectsQuestion('Confirm password', 'too-short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_confirmation_that_does_not_match(): void
    {
        $this->artisan('users:create', ['--name' => 'Ada', '--email' => 'ada@example.com'])
            ->expectsQuestion('Password (min. 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD.'-typo')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_an_invalid_e_mail_address(): void
    {
        $this->artisan('users:create', ['--name' => 'Ada', '--email' => 'not-an-address'])
            ->expectsQuestion('Password (min. 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
