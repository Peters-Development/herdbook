<?php

namespace App\Services\Alerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * One ntfy topic (herdbook.alerts.ntfy_url), shared by all accounts. First
 * in line for normal alerts and digests, second for critical ones
 * (AlertChannels).
 */
final class NtfyChannel implements AlertChannel
{
    public function isConfigured(): bool
    {
        return filled(config('herdbook.alerts.ntfy_url'));
    }

    /**
     * @throws AlertFailed
     */
    public function send(AlertMessage $message): void
    {
        $request = Http::timeout(10)->withHeaders(array_filter([
            'Title' => Str::limit($message->title, 245),
            // 5 only stands in for Pushover (a critical outage without Pushover set up).
            'Priority' => match (true) {
                $message->isUrgent() => '5',
                $message->problem => '4',
                default => '3',
            },
            'Tags' => $message->icon ?? ($message->problem ? 'rotating_light' : 'white_check_mark'),
            'Click' => $message->url,
        ]));

        if (filled(config('herdbook.alerts.ntfy_token'))) {
            $request = $request->withToken(config('herdbook.alerts.ntfy_token'));
        }

        try {
            $response = $request->withBody(Str::limit($message->body !== '' ? $message->body : $message->title, 4000), 'text/plain')
                ->post(config('herdbook.alerts.ntfy_url'));
        } catch (Throwable $exception) {
            throw new AlertFailed('ntfy unreachable: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new AlertFailed("ntfy refused the alert ({$response->status()}).");
        }
    }
}
