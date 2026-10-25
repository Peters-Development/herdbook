<?php

namespace App\Services\Alerts;

use App\Mail\AlertMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * Alerts by email, to every account that turned them on in its profile,
 * through the app's own mailer (MAIL_* in .env). Sent right away, not
 * queued: CheckAlerts has to know whether the alert got out.
 *
 * Mail does not get through Do Not Disturb and can sit in greylisting for
 * minutes; for waking someone up, Pushover goes first. Use an SMTP server
 * that does not run on the servers being watched.
 */
final class MailChannel implements AlertChannel
{
    /**
     * Transports that accept a message and deliver it to nobody: an alert
     * "sent" there would be marked as notified and lost.
     */
    private const array NON_DELIVERING = ['log', 'array'];

    public function isConfigured(): bool
    {
        return $this->canDeliver() && $this->recipients() !== [];
    }

    public function canDeliver(): bool
    {
        return $this->delivers((string) config('mail.default'));
    }

    /**
     * Succeeds when at least one recipient got it, like Pushover: one bad
     * address must not send the alert to everyone again. A server that
     * refuses one address is asked for the next; one that cannot be reached
     * (or refuses the login) is not asked again for every recipient.
     *
     * @throws AlertFailed with the last error, for `alerts:send --via=mail`
     */
    public function send(AlertMessage $message): void
    {
        $delivered = 0;
        $lastError = null;

        foreach ($this->recipients() as $address) {
            try {
                Mail::to($address)->send(new AlertMail($message));
                $delivered++;
            } catch (Throwable $exception) {
                Log::error('Alert email not sent.', ['to' => $address, 'exception' => $exception->getMessage()]);
                $lastError = $exception->getMessage();

                if ($exception instanceof TransportExceptionInterface && ! $exception instanceof UnexpectedResponseException) {
                    break;
                }
            }
        }

        if ($delivered === 0) {
            throw new AlertFailed('Email delivered to nobody'.($lastError !== null ? ": {$lastError}" : '.'));
        }
    }

    /**
     * Whether a mailer reaches anyone, by its transport. A failover or round
     * robin counts only when every mailer in it does: the stock failover ends
     * in `log`, where an alert would disappear once SMTP is down.
     */
    private function delivers(string $mailer, int $depth = 0): bool
    {
        $transport = config("mail.mailers.{$mailer}.transport");

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = (array) config("mail.mailers.{$mailer}.mailers");

            return $depth < 3 && $mailers !== [] && collect($mailers)->every(fn (string $inner): bool => $this->delivers($inner, $depth + 1));
        }

        return is_string($transport) && ! in_array($transport, self::NON_DELIVERING, true);
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        return User::query()->where('receives_alert_mail', true)->pluck('email')->all();
    }
}
