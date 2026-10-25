<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side protection on every response of the app (the panel, the map,
 * the status screen and its API). nginx adds nosniff to the static files.
 *
 * The Content-Security-Policy keeps everything on the app's own origin:
 * nothing from third parties (docs/development.md#nothing-from-third-parties),
 * no framing by another site, no form or <base> pointing elsewhere.
 * Filament, Livewire and Alpine need inline scripts and eval (Alpine
 * evaluates its expressions), so those stay allowed; the policy still
 * blocks scripts from any other origin.
 * Images may come over https: markdown in documents can link to one.
 */
class SecurityHeaders
{
    private const array POLICY = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob: https:",
        "font-src 'self' data:",
        "connect-src 'self'",
        "worker-src 'self' blob:",
        "frame-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => implode('; ', self::POLICY),
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            // Not even the origin to other sites: links to a repository or to
            // an invoicing tool would otherwise tell them the tailnet name.
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // Only over HTTPS (tailscale serve and Caddy): a browser ignores it on
        // plain HTTP, and local development runs on http://localhost.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
