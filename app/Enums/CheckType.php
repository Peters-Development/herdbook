<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CheckType: string implements HasLabel
{
    case Http = 'http';
    case Keyword = 'keyword';
    case Tls = 'tls';
    case Tcp = 'tcp';
    case Dns = 'dns';
    case Ping = 'ping';

    public function getLabel(): string
    {
        return match ($this) {
            self::Http => 'HTTP',
            self::Keyword => __('HTTP with keyword'),
            self::Tls => __('Certificate'),
            self::Tcp => __('TCP port'),
            self::Dns => 'DNS',
            self::Ping => 'Ping',
        };
    }

    public function isHttp(): bool
    {
        return $this === self::Http || $this === self::Keyword;
    }

    /**
     * Whether "down" means the thing itself is unreachable. A certificate
     * close to expiry or an unexpected DNS answer is a warning: the site
     * still answers.
     */
    public function measuresAvailability(): bool
    {
        return $this !== self::Tls && $this !== self::Dns;
    }

    /**
     * Seconds between checks when nothing else is set: a certificate does not
     * change by the minute.
     */
    public function defaultInterval(): int
    {
        return match ($this) {
            self::Tls => 21600,
            self::Dns => 300,
            default => 60,
        };
    }

    /**
     * Failures in a row before "down". A certificate or DNS answer is rarely
     * flaky, but one time-out must not be an alarm: two in a row are, and a
     * check that is not up is retried within minutes (DispatchChecks).
     */
    public function defaultFailureThreshold(): int
    {
        return match ($this) {
            self::Tls, self::Dns => 2,
            default => 3,
        };
    }

    /**
     * Good results in a row before "up" again. One good certificate or DNS
     * answer settles it; with a long interval, two would keep an alarm red
     * for hours.
     */
    public function defaultSuccessThreshold(): int
    {
        return match ($this) {
            self::Tls, self::Dns => 1,
            default => 2,
        };
    }
}
