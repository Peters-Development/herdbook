<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of project this is; most are our own.
 */
enum ProjectType: string implements HasLabel
{
    case Product = 'product';
    case ClientProject = 'client_project';
    case Website = 'website';
    case Internal = 'internal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Product => __('Own product'),
            self::ClientProject => __('Client project'),
            self::Website => __('Website'),
            self::Internal => __('Internal'),
        };
    }
}
