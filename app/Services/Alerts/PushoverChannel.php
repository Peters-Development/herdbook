<?php

namespace App\Services\Alerts;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * First in line for critical alerts (AlertChannels): Pushover is the one
 * service that gets through iOS's Do Not Disturb (Critical Alerts). Urgent
 * messages use priority 2: they repeat every minute until acknowledged, for
 * at most an hour. For everything else it is the last resort.
 */
final class PushoverChannel implements AlertChannel
{
    private const string ENDPOINT = 'https://api.pushover.net/1/messages.json';

    /** The code of an AlertFailed from test() when Pushover did not answer at all. */
    public const int UNREACHABLE = 1;

    public function isConfigured(): bool
    {
        return filled(config('herdbook.alerts.pushover_token')) && $this->recipients() !== [];
    }

    /**
     * Succeeds when at least one recipient got it: resending to everyone
     * because one key is wrong would wake the others twice.
     *
     * @throws AlertFailed
     */
    public function send(AlertMessage $message): void
    {
        $delivered = 0;

        foreach ($this->recipients() as $userKey) {
            try {
                $response = $this->push($userKey, $message);

                if ($response->successful()) {
                    $delivered++;
                } else {
                    Log::error('Pushover refused an alert.', ['status' => $response->status()]);
                }
            } catch (Throwable $exception) {
                Log::error('Pushover unreachable.', ['exception' => $exception->getMessage()]);
            }
        }

        if ($delivered === 0) {
            throw new AlertFailed('Pushover delivered to nobody.');
        }
    }

    /**
     * A test from the profile page: to this one key, also before it is saved
     * and whether or not its owner receives alerts. The exception carries
     * Pushover's own reason (a wrong key, a wrong app token).
     *
     * @throws AlertFailed
     */
    public function test(string $userKey, AlertMessage $message): void
    {
        try {
            $response = $this->push($userKey, $message);
        } catch (Throwable $exception) {
            Log::error('Pushover unreachable.', ['exception' => $exception->getMessage()]);

            throw new AlertFailed('Pushover is not reachable.', self::UNREACHABLE);
        }

        if (! $response->successful()) {
            $errors = array_filter((array) $response->json('errors', []), fn (mixed $error): bool => is_string($error));

            throw new AlertFailed(Str::limit(implode('; ', $errors), 300) ?: "HTTP {$response->status()}");
        }
    }

    /**
     * Stops the repeats of an emergency alarm (priority 2) once its check is
     * up again. Best effort: at worst the alarm runs out on its own.
     */
    public function cancel(string $tag): void
    {
        try {
            Http::asForm()->timeout(10)->post("https://api.pushover.net/1/receipts/cancel_by_tag/{$tag}.json", [
                'token' => config('herdbook.alerts.pushover_token'),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Could not cancel a Pushover emergency.', ['tag' => $tag, 'exception' => $exception->getMessage()]);
        }
    }

    private function push(string $userKey, AlertMessage $message): Response
    {
        return Http::asForm()->timeout(10)->post(self::ENDPOINT, array_filter([
            'token' => config('herdbook.alerts.pushover_token'),
            'user' => $userKey,
            // Pushover's limits: 250 for the title, 1024 for the message.
            'title' => Str::limit($message->title, 245),
            'message' => Str::limit($message->body !== '' ? $message->body : $message->title, 1020),
            'priority' => $message->isUrgent() ? 2 : 0,
            'retry' => $message->isUrgent() ? 60 : null,
            'expire' => $message->isUrgent() ? 3600 : null,
            'tags' => $message->isUrgent() ? $message->tag : null,
            'url' => $message->url,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        return User::query()
            ->where('receives_critical_alerts', true)
            ->whereNotNull('pushover_user_key')
            ->pluck('pushover_user_key')
            ->all();
    }
}
