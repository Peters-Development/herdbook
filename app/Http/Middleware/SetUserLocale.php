<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel in the language of the signed-in account (its profile), or in the
 * default one (APP_LOCALE). Runs after Filament's Authenticate, and on every
 * Livewire request too (persistent auth middleware).
 */
class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ($locale = $user->preferredLocale()) !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
