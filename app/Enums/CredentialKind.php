<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CredentialKind: string implements HasLabel
{
    case SshKey = 'ssh_key';
    case PanelLogin = 'panel_login';
    case Database = 'database';
    case ApiToken = 'api_token';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::SshKey => __('SSH key'),
            self::PanelLogin => __('Panel login'),
            self::Database => __('Database'),
            self::ApiToken => __('API token'),
            self::Other => __('Other'),
        };
    }
}
