<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The status screen: no session, no CSRF (routes/screen.php).
        then: fn () => Route::group([], base_path('routes/screen.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // On the host, `tailscale serve` terminates HTTPS and proxies to nginx
        // over loopback (docker/docker-compose.prod.yml).
        $middleware->trustProxies(at: '127.0.0.1');

        // CSP, framing, referrer and HSTS on every response (also /screen).
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
