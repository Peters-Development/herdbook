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
        };
    }
}
