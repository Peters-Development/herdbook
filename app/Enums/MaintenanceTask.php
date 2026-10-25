<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a maintenance consists of; one maintenance can be several.
 */
enum MaintenanceTask: string implements HasLabel
{
    /** apt update and upgrade, and the like. */
    case Updates = 'updates';

    case Reboot = 'reboot';

    /** Logged in, read the logs, looked around. */
    case Inspection = 'inspection';

    /** The big work: a new OS, PHP or MySQL version. */
    case Upgrade = 'upgrade';

    case Other = 'other';

    public function getLabel(?string $locale = null): string
    {
        return match ($this) {
            self::Updates => __('Updates', locale: $locale),
            self::Reboot => __('Reboot', locale: $locale),
            self::Inspection => __('Inspection', locale: $locale),
            self::Upgrade => __('Upgrade', locale: $locale),
            self::Other => __('Other', locale: $locale),
        };
    }

    /**
     * The label in the middle of a sentence ("updates and reboot"): its own
     * string, as not every language lowercases its nouns there.
     */
    private function inSentence(?string $locale): string
    {
        return match ($this) {
            self::Updates => __('updates', locale: $locale),
            self::Reboot => __('reboot', locale: $locale),
            self::Inspection => __('inspection', locale: $locale),
            self::Upgrade => __('upgrade', locale: $locale),
            self::Other => __('other', locale: $locale),
        };
    }

    /**
     * "Updates and reboot": the title of a maintenance that has none, in the
     * active language unless another is given.
     *
     * @param  list<string>  $tasks  values
     */
    public static function describe(array $tasks, ?string $locale = null): string
    {
        $chosen = collect(self::cases())
            ->filter(fn (self $task): bool => $task !== self::Other && in_array($task->value, $tasks, true))
            ->values();

        if ($chosen->count() < 2) {
            return $chosen->first()?->getLabel($locale) ?? __('Maintenance', locale: $locale);
        }

        $words = $chosen->map(fn (self $task, int $index): string => $index === 0 ? $task->getLabel($locale) : $task->inSentence($locale));

        return __(':items and :last', ['items' => $words->slice(0, -1)->join(', '), 'last' => $words->last()], $locale);
    }

    /**
     * Whether $title is the generated one for $tasks, in any language Herdbook
     * speaks: a title made while someone used Dutch keeps following the work
     * when someone using English changes it.
     *
     * @param  list<string>  $tasks  values
     */
    public static function isDescription(?string $title, array $tasks): bool
    {
        foreach (array_keys(config('herdbook.locales')) as $locale) {
            if ($title === self::describe($tasks, $locale)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tasks a server page says "last done" for: not "Other".
     *
     * @return list<self>
     */
    public static function tracked(): array
    {
        return array_values(array_filter(self::cases(), fn (self $task): bool => $task !== self::Other));
    }
}
