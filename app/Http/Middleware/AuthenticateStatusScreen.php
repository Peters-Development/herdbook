<?php

namespace App\Http\Middleware;

use App\Models\StatusScreen;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The status screen's API: a Bearer token instead of a session (there is
 * no login on a wall display). The token is checked first, then limited per
 * screen; wrong tokens have a limit of their own, which never blocks a good
 * one. Behind `tailscale serve` every request comes from one address, so
 * nothing is keyed on the IP.
 */
class AuthenticateStatusScreen
{
    private const int PER_SCREEN_PER_MINUTE = 20;

    private const int WRONG_TOKENS_PER_MINUTE = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $screen = filled($token) ? StatusScreen::findByToken($token) : null;

        if ($screen === null) {
            if (RateLimiter::tooManyAttempts('status-screen:wrong-token', self::WRONG_TOKENS_PER_MINUTE)) {
                return $this->refuse(__('Too many attempts. Try again in a minute.'), 429);
            }

            RateLimiter::hit('status-screen:wrong-token', 60);

            return $this->refuse(__('This code is unknown or has been revoked.'), 401);
        }

        $key = "status-screen:{$screen->id}";

        if (RateLimiter::tooManyAttempts($key, self::PER_SCREEN_PER_MINUTE)) {
            return $this->refuse(__('Too many requests from this screen. It will try again shortly.'), 429);
        }

        RateLimiter::hit($key, 60);
        $screen->markSeen();

        return $next($request);
    }

    private function refuse(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status, ['Cache-Control' => 'no-store']);
    }
}
