<?php

namespace App\Models\Concerns;

use Closure;

/**
 * Whether what is created now gets an author (RecordsAuthor): monitoring
 * writes without one, also when a person's "Check now" set it off. The
 * activity log still names that person as the causer of what changed: it
 * records who set a change off, not who wrote it.
 */
final class Authorship
{
    private static bool $paused = false;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function without(Closure $callback): mixed
    {
        $paused = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $paused;
        }
    }

    public static function paused(): bool
    {
        return self::$paused;
    }
}
