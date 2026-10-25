<?php

namespace App\Services\Alerts;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which channel an alert goes out on: the first one that is set up and
 * delivers, and only that one. Every channel works on its own, so a host with
 * only SMTP (or only ntfy) still gets every alert.
 *
 * - Critical: Pushover (the only one through iOS's Do Not Disturb), then
 *   ntfy (priority 5), then email.
 * - Everything else: ntfy, then email, then Pushover.
 *
 * One instance per command run, never a singleton: a channel that failed is
 * skipped for the rest of the run (a mail server that hangs costs one
 * timeout, not one per message), and tried again by the next run.
 */
final class AlertChannels
{
    /**
     * @var array<class-string<AlertChannel>, true>
     */
    private array $failed = [];

    public function __construct(
        private readonly PushoverChannel $pushover,
        private readonly NtfyChannel $ntfy,
        private readonly MailChannel $mail,
    ) {}

    /**
     * The channels that are set up, in order of preference. A channel that
     * cannot even tell (Pushover and email look up their recipients, and the
     * database may be down) is left out, so the others still get their turn.
     *
     * @return list<AlertChannel>
     */
    public function for(bool $critical): array
    {
        $ordered = $critical ? [$this->pushover, $this->ntfy, $this->mail] : [$this->ntfy, $this->mail, $this->pushover];

        return array_values(array_filter($ordered, function (AlertChannel $channel): bool {
            try {
                return $channel->isConfigured();
            } catch (Throwable $exception) {
                Log::error('Alert channel skipped: could not tell whether it is set up.', ['channel' => $channel::class, 'exception' => $exception->getMessage()]);

                return false;
            }
        }));
    }

    /**
     * One channel by name, for `alerts:send --via`: trying out email while
     * ntfy comes first, for instance.
     */
    public function named(string $name): ?AlertChannel
    {
        return match ($name) {
            'pushover' => $this->pushover,
            'ntfy' => $this->ntfy,
            'mail' => $this->mail,
            default => null,
        };
    }

    /**
     * @throws AlertFailed when no channel is set up, or none delivered
     */
    public function send(AlertMessage $message): void
    {
        $this->sendVia($this->for($message->critical), $message);
    }

    /**
     * @param  list<AlertChannel>  $channels  from for(): callers that send several messages look them up once
     *
     * @throws AlertFailed when no channel delivered
     */
    public function sendVia(array $channels, AlertMessage $message): void
    {
        $failure = new AlertFailed($channels === [] ? 'No notification channel configured.' : 'Every channel failed earlier in this run.');

        foreach ($channels as $channel) {
            if (isset($this->failed[$channel::class])) {
                continue;
            }

            try {
                $channel->send($message);

                return;
            } catch (Throwable $exception) {
                $this->failed[$channel::class] = true;

                if (! $exception instanceof AlertFailed) {
                    Log::error('Alert channel crashed.', ['channel' => $channel::class, 'exception' => $exception->getMessage()]);
                    $exception = new AlertFailed($channel::class." crashed: {$exception->getMessage()}", previous: $exception);
                }

                $failure = $exception;
            }
        }

        throw $failure;
    }
}
