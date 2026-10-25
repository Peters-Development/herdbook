<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\EditProfile;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class PushoverTestButtonTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        config(['herdbook.alerts.pushover_token' => 'app-token']);
        $this->key = str_repeat('u', 30);
    }

    public function test_it_sends_a_test_to_the_key_in_the_field_before_it_is_saved(): void
    {
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
        $user = $this->signIn();

        $this->profile()
            ->fillForm(['pushover_user_key' => $this->key])
            ->callAction($this->button(), ['kind' => 'normal'])
            ->assertNotified('Testmelding verstuurd');

        Http::assertSent(fn (Request $request): bool => $request['user'] === $this->key
            && $request['token'] === 'app-token'
            && $request['priority'] === 0
            && $request['title'] === 'Herdbook: testmelding');
        $this->assertNull($user->fresh()->pushover_user_key, 'Testing does not save the key.');
    }

    public function test_an_emergency_test_repeats_until_acknowledged(): void
    {
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
        $this->signIn();

        $this->profile()
            ->fillForm(['pushover_user_key' => $this->key])
            ->callAction($this->button(), ['kind' => 'urgent']);

        Http::assertSent(fn (Request $request): bool => $request['priority'] === 2
            && $request['retry'] === 60
            && $request['expire'] === 3600
            && str_starts_with((string) $request['tags'], 'herdbook-test-'));
    }

    public function test_pushover_refusing_shows_its_reason(): void
    {
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 0, 'errors' => ['user identifier is not a valid user']], 400)]);
        $this->signIn();

        $this->profile()
            ->fillForm(['pushover_user_key' => $this->key])
            ->callAction($this->button(), ['kind' => 'normal'])
            ->assertNotified('Pushover nam de melding niet aan');
    }

    public function test_pushover_not_answering_says_so_in_dutch(): void
    {
        Http::fake(['api.pushover.net/*' => fn () => throw new ConnectionException('timeout')]);
        $this->signIn();

        $this->profile()
            ->fillForm(['pushover_user_key' => $this->key])
            ->callAction($this->button(), ['kind' => 'normal'])
            ->assertNotified('Pushover is niet bereikbaar');
    }

    public function test_a_malformed_key_is_not_sent(): void
    {
        Http::fake();
        $this->signIn();

        $this->profile()
            ->fillForm(['pushover_user_key' => 'te-kort'])
            ->callAction($this->button(), ['kind' => 'normal'])
            ->assertNotified('Vul eerst een geldige user key in');

        Http::assertNothingSent();
    }

    public function test_without_an_app_token_there_is_no_button(): void
    {
        config(['herdbook.alerts.pushover_token' => null]);
        $this->signIn();

        $this->profile()
            ->assertActionHidden($this->button())
            ->assertSee('Herdbook heeft nog geen app-token');
    }

    private function profile(): mixed
    {
        return Livewire::test(EditProfile::class);
    }

    private function button(): TestAction
    {
        return TestAction::make('testPushover')->schemaComponent('pushover_user_key', schema: 'form');
    }
}
