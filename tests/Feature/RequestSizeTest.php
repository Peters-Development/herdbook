<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * nginx and PHP agree on how large a request may be: when nginx lets more
 * through than PHP takes, PHP drops the body without a word, and a save
 * fails in a way nobody can explain.
 */
class RequestSizeTest extends TestCase
{
    public function test_nginx_and_php_take_the_same_request_size_in_development_and_production(): void
    {
        $this->assertSame(
            [$this->setting('docker/nginx/default.conf', '/client_max_body_size\s+(\S+);/'), $this->setting('docker/nginx/production.conf.template', '/client_max_body_size\s+(\S+);/')],
            [$this->setting('docker/php/overwrites.ini', '/^post_max_size\s*=\s*(\S+)$/m'), $this->setting('docker/php/production.ini', '/^post_max_size\s*=\s*(\S+)$/m')],
        );
    }

    public function test_an_upload_can_be_no_larger_than_the_request_it_comes_in(): void
    {
        foreach (['docker/php/overwrites.ini', 'docker/php/production.ini'] as $file) {
            $this->assertSame($this->setting($file, '/^post_max_size\s*=\s*(\S+)$/m'), $this->setting($file, '/^upload_max_filesize\s*=\s*(\S+)$/m'), $file);
        }
    }

    private function setting(string $file, string $pattern): string
    {
        $this->assertSame(1, preg_match($pattern, (string) file_get_contents(base_path($file)), $match), "{$file}: {$pattern}");

        return $match[1];
    }
}
