<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * On the host, `tailscale serve` terminates HTTPS and reaches nginx over
 * loopback; only that hop may tell the app the request was secure.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/secure', fn () => response()->json(['secure' => request()->isSecure()]))
            ->middleware('web');
    }

    public function test_forwarded_https_from_loopback_counts_as_secure(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->getJson('/_test/secure')
            ->assertJson(['secure' => true]);
    }

    public function test_forwarded_https_from_anywhere_else_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->getJson('/_test/secure')
            ->assertJson(['secure' => false]);
    }
}
