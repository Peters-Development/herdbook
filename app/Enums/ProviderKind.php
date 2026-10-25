<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProviderKind: string implements HasLabel
{
    case Hosting = 'hosting';
    case Registrar = 'registrar';
    case Dns = 'dns';
    case Mail = 'mail';
    case Internet = 'internet';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hosting => __('Hosting'),
            self::Registrar => __('Registrar'),
            self::Dns => 'DNS',
            self::Mail => __('Mail'),
            self::Internet => __('Internet'),
            self::Other => __('Other'),
        };
    }
}
