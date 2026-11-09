<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;

/**
 * A form or modal that refused to save over a change made since it was
 * opened (GuardsChanges): it says so, and the person reloads.
 */
final class ChangedMeanwhile
{
    public static function notify(string $title): void
    {
        Notification::make()
            ->warning()
            ->title($title)
            ->body(__('Yours was not saved: copy what you typed, then reload the page to see the change.'))
            ->persistent()
            ->send();
    }
}
