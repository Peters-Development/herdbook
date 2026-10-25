<?php

namespace App\Services\Alerts;

use App\Enums\AlertLevel;
use App\Filament\Resources\Checks\CheckResource;
use App\Models\CheckEvent;
use App\Services\Checks\ConnectivityGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Sends the check events nobody has heard about yet.
 *
 * - Critical and normal events go out on the first channel that is set up
 *   and delivers, each in their own order (AlertChannels). Silent ones go
 *   nowhere.
 * - Per check, a down and its recovery that are both still pending become
 *   one "briefly down" message: no alarm for something that is over.
 * - Many checks at once become one message: twenty alarms help nobody.
 * - A message that does not get out is tried again next minute; while the
 *   host's own line is down nothing is counted. After GIVE_UP_AFTER_HOURS the
 *   event is marked as failed (shown in the panel), not as notified.
 * - Messages stay bare: which check, up or down, and when.
 */
final class CheckAlerts
{
    public const int GIVE_UP_AFTER_HOURS = 6;

    private const string GROUP_TAG = 'checks-group';

    public function __construct(
        private readonly AlertChannels $channels,
        private readonly PushoverChannel $pushover,
        private readonly ConnectivityGuard $guard,
    ) {}

    public function sendPending(): int
    {
        $events = CheckEvent::query()
            ->with('check.hostname.environment.project')
            ->whereNull('notified_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        $byLevel = $events->groupBy(fn (CheckEvent $event): string => $event->check->alert_level->value);

        $this->markNotified($byLevel->get(AlertLevel::Silent->value, collect()));

        if ($this->deliver($byLevel->get(AlertLevel::Critical->value, collect()), critical: true)) {
            $this->deliver($byLevel->get(AlertLevel::Normal->value, collect()), critical: false);
        }

        return $events->count();
    }

    /**
     * @param  Collection<int, CheckEvent>  $events
     * @return bool false when the own line turned out to be down: stop this run
     */
    private function deliver(Collection $events, bool $critical): bool
    {
        if ($events->isEmpty()) {
            return true;
        }

        $channels = $this->channels->for($critical);

        if ($channels === []) {
            Log::warning('Check events not sent: no notification channel configured.', ['events' => self::ids($events)]);
            $this->markFailed($events);

            return true;
        }

        if ($critical && collect($channels)->contains(fn (AlertChannel $channel): bool => $channel instanceof PushoverChannel)) {
            $recovered = $events->filter(fn (CheckEvent $event): bool => ! $event->wentDown());

            $recovered->each(fn (CheckEvent $event) => $this->pushover->cancel(self::tag($event->check_id)));

            // A grouped alarm covers several checks; recoveries end it too.
            if ($recovered->isNotEmpty()) {
                $this->pushover->cancel(self::GROUP_TAG);
            }
        }

        foreach ($this->messages($events, $critical) as [$message, $covered]) {
            try {
                $this->channels->sendVia($channels, $message);
            } catch (AlertFailed $failure) {
                if (! $this->guard->isOnline()) {
                    Log::warning('Check notifications held back: the own connection seems down.');

                    return false;
                }

                $this->countFailure($covered, $failure);

                continue;
            }

            $this->markNotified($covered);
        }

        return true;
    }

    /**
     * @param  Collection<int, CheckEvent>  $events
     * @return list<array{AlertMessage, Collection<int, CheckEvent>}>
     */
    private function messages(Collection $events, bool $critical): array
    {
        $perCheck = $events->groupBy('check_id')->map(fn (Collection $checkEvents): array => [
            'events' => $checkEvents,
            'last' => $checkEvents->last(),
            'blip' => ! $checkEvents->last()->wentDown() && $checkEvents->contains(fn (CheckEvent $event): bool => $event->wentDown()),
        ])->values();

        if ($perCheck->count() >= (int) config('herdbook.checks.group_alerts_from')) {
            $down = $perCheck->filter(fn (array $item): bool => $item['last']->wentDown());

            return [[new AlertMessage(
                title: __(':count checks changed (:down down)', ['count' => $perCheck->count(), 'down' => $down->count()]),
                body: $perCheck->map(fn (array $item): string => self::headline($item['last'], $item['blip']))->implode("\n"),
                problem: $down->isNotEmpty(),
                critical: $critical,
                url: CheckResource::getUrl('index'),
                tag: self::GROUP_TAG,
            ), $events]];
        }

        return $perCheck->map(function (array $item) use ($critical): array {
            /** @var CheckEvent $last */
            $last = $item['last'];
            $time = fn (CheckEvent $event): string => $event->occurred_at->timezone(config('herdbook.display_timezone'))->format('H:i');

            return [new AlertMessage(
                title: self::headline($last, $item['blip']),
                body: $item['blip']
                    ? $time($item['events']->first(fn (CheckEvent $event): bool => $event->wentDown())).'–'.$time($last)
                    : $last->occurred_at->timezone(config('herdbook.display_timezone'))->translatedFormat(__('formats.datetime')),
                problem: $last->wentDown(),
                critical: $critical,
                url: CheckResource::getUrl('view', ['record' => $last->check_id]),
                tag: self::tag($last->check_id),
            ), $item['events']];
        })->all();
    }

    private static function headline(CheckEvent $last, bool $blip): string
    {
        $replace = ['check' => $last->check->displayName()];

        return match (true) {
            $blip => __('Briefly down: :check', $replace),
            $last->wentDown() => __('Down: :check', $replace),
            default => __('Up again: :check', $replace),
        };
    }

    /**
     * The Pushover tag of a check's emergency alarm.
     */
    public static function tag(int $checkId): string
    {
        return "check-{$checkId}";
    }

    /**
     * @param  Collection<int, CheckEvent>  $events
     */
    private function markNotified(Collection $events): void
    {
        if ($events->isNotEmpty()) {
            CheckEvent::query()->whereKey(self::ids($events))->update(['notified_at' => Date::now()]);
        }
    }

    /**
     * @param  Collection<int, CheckEvent>  $events
     */
    private function markFailed(Collection $events): void
    {
        if ($events->isNotEmpty()) {
            CheckEvent::query()->whereKey(self::ids($events))->update(['notified_at' => Date::now(), 'notify_failed' => true]);
        }
    }

    /**
     * @param  Collection<int, CheckEvent>  $events
     */
    private function countFailure(Collection $events, AlertFailed $failure): void
    {
        CheckEvent::query()->whereKey(self::ids($events))->increment('notify_attempts');

        $tooOld = $events->filter(fn (CheckEvent $event): bool => $event->occurred_at->lt(Date::now()->subHours(self::GIVE_UP_AFTER_HOURS)));

        if ($tooOld->isNotEmpty()) {
            Log::error('Check notifications given up on.', ['events' => self::ids($tooOld), 'last_error' => $failure->getMessage()]);
            $this->markFailed($tooOld);
        } else {
            Log::warning('Check notification failed; trying again next minute.', ['events' => self::ids($events), 'error' => $failure->getMessage()]);
        }
    }

    /**
     * @param  Collection<int, CheckEvent>  $events
     * @return list<int>
     */
    private static function ids(Collection $events): array
    {
        return $events->map(fn (CheckEvent $event): int => $event->getKey())->values()->all();
    }
}
