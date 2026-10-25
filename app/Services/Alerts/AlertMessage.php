<?php

namespace App\Services\Alerts;

/**
 * A notification, kept bare: who reads it on a lock screen (or who runs
 * Pushover's servers) learns a name, a state and a time, nothing more.
 */
final readonly class AlertMessage
{
    /**
     * @param  bool  $problem  bad news (something went down), not a recovery or a digest
     * @param  bool  $critical  from a critical check: may wake someone up
     * @param  ?string  $tag  identifies the alarm, so a recovery can cancel its repeats
     * @param  ?string  $icon  an ntfy emoji tag; default: by $problem
     */
    public function __construct(
        public string $title,
        public string $body,
        public bool $problem = false,
        public bool $critical = false,
        public ?string $url = null,
        public ?string $tag = null,
        public ?string $icon = null,
    ) {}

    /**
     * Wakes someone up: a critical check went down.
     */
    public function isUrgent(): bool
    {
        return $this->problem && $this->critical;
    }
}
