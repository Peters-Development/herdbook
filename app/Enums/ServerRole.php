<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a server does for an environment.
 */
enum ServerRole: string implements HasLabel
{
    case App = 'app';
    case Database = 'database';
    case Storage = 'storage';
    case Mail = 'mail';
    case Proxy = 'proxy';
    case Worker = 'worker';
    case Other = 'other';
    /**
     * Only through a domain (Domain::dnsServer()): DNS belongs to the domain,
     * not to an environment, so it is never assigned (assignableOptions()).
     */
    case Dns = 'dns';

    public function getLabel(): string
    {
        return match ($this) {
            self::App => __('App'),
            self::Database => __('Database'),
            self::Storage => __('Storage'),
            self::Mail => __('Mail'),
            self::Proxy => __('Proxy'),
            self::Worker => __('Worker'),
            self::Other => __('Other'),
            self::Dns => 'DNS',
        };
    }

    /**
     * The roles a server can have in an environment, as options.
     *
     * @return array<string, string>
     */
    public static function assignableOptions(): array
    {
        return collect(self::cases())
            ->reject(fn (self $role): bool => $role === self::Dns)
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->getLabel()])
            ->all();
    }
}
