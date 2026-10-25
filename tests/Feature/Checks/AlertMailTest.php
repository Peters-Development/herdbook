<?php

namespace Tests\Feature\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Filament\Pages\Auth\EditProfile;
use App\Mail\AlertMail;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\Domain;
use App\Models\User;
use App\Services\Alerts\AlertMessage;
use App\Services\Checks\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Concerns\SignsIn;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

class AlertMailTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        // Only email is set up: no Pushover, no ntfy.
        config(['herdbook.alerts.pushover_token' => null, 'herdbook.alerts.ntfy_url' => null, 'mail.default' => 'smtp']);
        Http::preventStrayRequests();
        Mail::fake();
        $this->app->instance(NetworkProbe::class, new FakeProbe);

        $this->recipient = User::factory()->create(['email' => 'ops@example.net', 'receives_alert_mail' => true]);
        User::factory()->create(['email' => 'stil@example.net', 'receives_alert_mail' => false]);
    }

    private function event(AlertLevel $level = AlertLevel::Normal): CheckEvent
    {
        return Check::factory()->create(['alert_level' => $level])->events()->create([
            'from_state' => CheckState::Up,
            'to_state' => CheckState::Down,
            'occurred_at' => now(),
            'error' => 'HTTP 503',
        ]);
    }

    public function test_an_outage_goes_by_mail_to_who_turned_it_on(): void
    {
        $event = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo('ops@example.net')
            && str_starts_with($mail->alert->title, 'Down: '));
        $this->assertNotNull($event->fresh()->notified_at);
        $this->assertFalse($event->fresh()->notify_failed);
    }

    public function test_the_mail_is_bare_text_with_a_link(): void
    {
        $mail = new AlertMail(new AlertMessage(
            title: 'Down: app.example.net & co (HTTPS)',
            body: '22-10-2026 14:05',
            problem: true,
            critical: true,
            url: 'https://herdbook.example/checks/1',
        ));

        $mail->assertHasSubject('Down: app.example.net & co (HTTPS)');
        // plain text: no HTML entities
        $mail->assertSeeInText('Down: app.example.net & co (HTTPS)');
        $mail->assertSeeInText('Bekijken: https://herdbook.example/checks/1');
        $this->assertSame('1 (Highest)', $mail->headers()->text['X-Priority']);
    }

    public function test_a_recovery_is_not_marked_urgent(): void
    {
        $mail = new AlertMail(new AlertMessage(title: 'Weer up: app.example.net', body: '', critical: true));

        $this->assertArrayNotHasKey('X-Priority', $mail->headers()->text);
        // machine-sent: no out-of-office replies to every alert
        $this->assertSame('auto-generated', $mail->headers()->text['Auto-Submitted']);
        $mail->assertDontSeeInText('Bekijken');
    }

    public function test_the_error_does_not_leave_the_host(): void
    {
        $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => ! str_contains($mail->render(), 'HTTP 503'));
    }

    public function test_a_critical_outage_goes_by_mail_without_pushover_and_ntfy(): void
    {
        $this->event(AlertLevel::Critical);

        $this->artisan('checks:notify')->assertSuccessful();

        Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->alert->isUrgent());
    }

    public function test_mail_is_the_fallback_when_ntfy_fails(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake(['ntfy.example/*' => Http::response('', 500)]);
        $event = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ntfy.example'));
        Mail::assertSentCount(1);
        $this->assertNotNull($event->fresh()->notified_at);
    }

    public function test_ntfy_goes_first_when_it_works(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake(['ntfy.example/*' => Http::response('ok')]);
        $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ntfy.example'));
        Mail::assertNothingSent();
    }

    public function test_a_mailer_that_delivers_nowhere_is_no_channel(): void
    {
        config(['mail.default' => 'log']);
        $event = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertTrue($event->fresh()->notify_failed);
    }

    public function test_without_anyone_who_wants_mail_there_is_no_channel(): void
    {
        $this->recipient->update(['receives_alert_mail' => false]);
        $event = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertTrue($event->fresh()->notify_failed);
    }

    public function test_a_mail_server_that_refuses_is_tried_again(): void
    {
        $this->useMailer(fn () => throw new TransportException('Connection refused'));
        $event = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        $this->assertNull($event->fresh()->notified_at);
        $this->assertSame(1, $event->fresh()->notify_attempts);
    }

    public function test_the_renewal_digest_goes_by_mail_without_ntfy(): void
    {
        Domain::factory()->create(['expires_on' => now()->addDays(20)->toDateString()]);

        $this->artisan('renewals:digest')->assertSuccessful();

        Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => str_contains($mail->alert->title, 'verlenging'));
    }

    public function test_an_old_backup_is_reported_by_mail(): void
    {
        $marker = sys_get_temp_dir().'/herdbook-backup-marker-'.uniqid();
        touch($marker, now()->subDays(3)->getTimestamp());
        config(['herdbook.backup_marker' => $marker]);

        try {
            $this->artisan('backups:check')->assertFailed();
        } finally {
            unlink($marker);
        }

        Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->alert->title === 'Backup is verouderd');
    }

    public function test_an_alert_can_be_sent_by_hand(): void
    {
        $this->artisan('alerts:send', ['title' => 'Backup mislukt', 'body' => 'Zie journalctl', '--problem' => true])
            ->assertSuccessful();

        Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->alert->title === 'Backup mislukt'
            && $mail->alert->problem
            && ! $mail->alert->critical);
    }

    public function test_sending_by_hand_fails_without_a_channel(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('alerts:send', ['title' => 'Test'])->assertFailed();
    }

    public function test_a_critical_outage_that_pushover_delivers_sends_no_mail(): void
    {
        config(['herdbook.alerts.pushover_token' => 'app-token']);
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
        User::factory()->create(['pushover_user_key' => str_repeat('u', 30), 'receives_critical_alerts' => true]);
        $this->event(AlertLevel::Critical);

        $this->artisan('checks:notify')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.pushover.net'));
        Mail::assertNothingSent();
    }

    public function test_a_normal_outage_tries_mail_before_pushover(): void
    {
        config(['herdbook.alerts.pushover_token' => 'app-token', 'herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake([
            'ntfy.example/*' => Http::response('', 500),
            'api.pushover.net/*' => Http::response(['status' => 1]),
        ]);
        User::factory()->create(['pushover_user_key' => str_repeat('u', 30), 'receives_critical_alerts' => true]);
        $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        Mail::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.pushover.net'));
    }

    public function test_one_bad_address_does_not_send_the_alert_twice(): void
    {
        User::factory()->create(['email' => 'kapot@example.net', 'receives_alert_mail' => true]);
        $delivered = $this->useMailer(function (SentMessage $message): void {
            if (str_contains($message->getEnvelope()->getRecipients()[0]->getAddress(), 'kapot')) {
                throw new UnexpectedResponseException('550 No such user');
            }
        });
        $event = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();
        $this->artisan('checks:notify')->assertSuccessful();

        $this->assertSame(['ops@example.net'], $delivered->all());
        $this->assertNotNull($event->fresh()->notified_at);
    }

    public function test_a_server_that_cannot_be_reached_is_not_asked_again_for_every_recipient(): void
    {
        User::factory()->create(['email' => 'tweede@example.net', 'receives_alert_mail' => true]);
        $attempts = 0;
        $this->useMailer(function () use (&$attempts): void {
            $attempts++;

            throw new TransportException('Connection could not be established with host "smtp.example.net:587"');
        });
        $event = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        $this->assertSame(1, $attempts);
        $this->assertSame(1, $event->fresh()->notify_attempts);
    }

    public function test_mail_can_be_tried_on_its_own_while_ntfy_comes_first(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake();

        $this->artisan('alerts:send', ['title' => 'Test', '--via' => 'mail'])->assertSuccessful();

        Mail::assertSentCount(1);
        Http::assertNothingSent();
    }

    public function test_trying_one_channel_shows_why_it_failed(): void
    {
        $this->useMailer(fn () => throw new TransportException('Failed to authenticate on SMTP server'));

        $this->artisan('alerts:send', ['title' => 'Test', '--via' => 'mail'])
            ->expectsOutputToContain('Failed to authenticate on SMTP server')
            ->assertFailed();
    }

    public function test_trying_a_channel_that_is_not_set_up_fails(): void
    {
        $this->artisan('alerts:send', ['title' => 'Test', '--via' => 'ntfy'])->assertFailed();
        $this->artisan('alerts:send', ['title' => 'Test', '--via' => 'sms'])->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_a_channel_that_fails_is_not_tried_again_in_the_same_run(): void
    {
        $attempts = 0;
        $this->useMailer(function () use (&$attempts): void {
            $attempts++;

            throw new TransportException('Connection timed out');
        });
        $first = $this->event();
        $second = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        // two messages, one wait for the dead server
        $this->assertSame(1, $attempts);
        $this->assertSame(1, $first->fresh()->notify_attempts);
        $this->assertSame(1, $second->fresh()->notify_attempts);
    }

    public function test_a_channel_that_cannot_look_up_its_recipients_leaves_the_others_their_turn(): void
    {
        // The database is gone (here: the users table), so Pushover and email
        // cannot tell who gets them; ntfy needs no database.
        config(['herdbook.alerts.pushover_token' => 'app-token', 'herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake(['ntfy.example/*' => Http::response('ok')]);
        Schema::drop('users');

        $this->artisan('alerts:send', ['title' => 'Backup mislukt', '--problem' => true, '--critical' => true])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ntfy.example'));
    }

    public function test_a_failover_that_ends_in_the_log_is_no_channel(): void
    {
        // The stock failover: SMTP, and the log once SMTP is down.
        config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']]]);
        $event = $this->event();

        $this->artisan('checks:notify')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertTrue($event->fresh()->notify_failed);
    }

    public function test_the_profile_turns_mail_on(): void
    {
        $user = $this->signIn();

        Livewire::test(EditProfile::class)
            ->assertSee('Naar het e-mailadres hierboven')
            ->fillForm(['receives_alert_mail' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($user->fresh()->receives_alert_mail);
    }

    public function test_the_profile_says_when_herdbook_cannot_mail(): void
    {
        config(['mail.default' => 'log']);
        $this->signIn();

        Livewire::test(EditProfile::class)->assertSee('Herdbook kan nog niet mailen');
    }

    /**
     * A real mailer in place of the fake, whose transport runs $send for each
     * message (throw to refuse it).
     *
     * @return Collection<int, string> the addresses that got a message
     */
    private function useMailer(callable $send): Collection
    {
        $delivered = collect();
        $manager = new MailManager($this->app);
        $manager->extend('test', fn () => new class($send, $delivered) extends AbstractTransport
        {
            public function __construct(private $send, private Collection $delivered)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                ($this->send)($message);
                $this->delivered->push($message->getEnvelope()->getRecipients()[0]->getAddress());
            }

            public function __toString(): string
            {
                return 'test://';
            }
        });
        Mail::swap($manager);

        config(['mail.mailers.test' => ['transport' => 'test'], 'mail.default' => 'test']);

        return $delivered;
    }
}
