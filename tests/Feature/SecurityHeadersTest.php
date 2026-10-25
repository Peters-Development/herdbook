<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_kind_of_response_carries_the_headers(): void
    {
        foreach (['/login', '/screen', '/up'] as $path) {
            $this->get($path)
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'same-origin')
                ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        }

        // Also a refusal of the status screen's API.
        $this->getJson('/api/screen')->assertUnauthorized()->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_the_content_security_policy_keeps_everything_on_the_own_origin(): void
    {
        $policy = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringNotContainsString('https:', str_replace("img-src 'self' data: blob: https:", '', $policy), 'Only images may come from elsewhere.');
    }

    public function test_hsts_only_over_https(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
