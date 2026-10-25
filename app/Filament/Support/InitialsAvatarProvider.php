<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The user's initials, drawn here: Filament's default asks ui-avatars.com for
 * them, which sends the name to a third party and fails when the host is offline.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(fn (string $word): string => mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $word) ?? '', 0, 1))
            ->filter()
            ->take(2)
            ->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#17181b"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#fdc700" '
            .'font-family="IBM Plex Sans, ui-sans-serif, system-ui, sans-serif" font-size="26" font-weight="600">'
            .e(mb_strtoupper($initials))
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
