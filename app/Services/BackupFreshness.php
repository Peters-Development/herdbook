<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * When the last good backup ran. docker/backup/backup.sh touches the marker
 * after a run without failures; without a marker, backups are not set up
 * yet (nothing to warn about), an old one means they stopped.
 */
final class BackupFreshness
{
    /**
     * Null: no marker, backups are not set up yet.
     */
    public function lastSuccess(): ?CarbonImmutable
    {
        $marker = (string) config('herdbook.backup_marker');

        clearstatcache(true, $marker);

        if (! is_file($marker)) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) filemtime($marker));
    }

    public function isStale(): bool
    {
        $last = $this->lastSuccess();

        return $last !== null && (int) $last->diffInHours(now()) > (int) config('herdbook.backup_max_age_hours');
    }
}
