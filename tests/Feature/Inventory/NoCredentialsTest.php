<?php

namespace Tests\Feature\Inventory;

use App\Rules\NoCredentials;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoCredentialsTest extends TestCase
{
    /**
     * Built at run time, so that no secret scanner mistakes the repository's
     * own tests for a leak.
     *
     * @return array<string, array{string}>
     */
    public static function credentials(): array
    {
        $digits = str_repeat('a1B2', 12);

        return [
            'github token' => ['token: gh'.'p_'.substr($digits, 0, 36)],
            'github fine-grained token' => ['github'.'_pat_'.str_repeat('a1_B', 21)],
            'gitlab token' => ['glp'.'at-'.substr($digits, 0, 20)],
            'slack token' => ['xo'.'xb-'.substr($digits, 0, 12)],
            'stripe key' => ['sk'.'_live_'.substr($digits, 0, 24)],
            'aws key' => ['AK'.'IA'.str_repeat('Z', 15).'7'],
            'google key' => ['AI'.'za'.substr($digits, 0, 35)],
            'llm key' => ['sk-'.'ant-'.substr($digits, 0, 32)],
            'herdbook token' => ['hb'.'_'.substr($digits, 0, 48)],
            'jwt' => ['ey'.'J'.substr($digits, 0, 10).'.ey'.'J'.substr($digits, 0, 10).'.'.substr($digits, 0, 20)],
            'bearer header' => ['curl -H "Authorization: Bearer '.substr($digits, 0, 30).'"'],
            'password in a url' => ['DATABASE_URL=mysql://app:'.substr($digits, 0, 12).'@db:3306/app'],
            'env line' => ['DB_PASSWORD='.substr($digits, 0, 20)],
            'api key line' => ['api_key: '.substr($digits, 0, 24)],
            'laravel app key' => ['APP_KEY=base64:'.substr($digits, 0, 32).'='],
            'a secret key base' => ['SECRET_KEY_BASE='.substr($digits, 0, 30)],
            'a short key name' => ['DB_PASS='.substr($digits, 0, 20)],
            'json' => ['{"password": "'.substr($digits, 0, 20).'"}'],
            'php' => ["'password' => '".substr($digits, 0, 20)."',"],
            'basic auth' => ['Authorization: Basic '.base64_encode('deploy:'.substr($digits, 0, 16))],
            'token auth' => ['Authorization: Token '.str_repeat('0a1b', 10)],
            'an api key header' => ['curl -H "X-API-KEY: '.str_repeat('0a1b', 8).'"'],
            'a private key value' => ['PRIVATE_KEY=0x'.str_repeat('0a1b', 16)],
        ];
    }

    #[DataProvider('credentials')]
    public function test_a_credential_is_refused_without_repeating_it(string $value): void
    {
        $validator = Validator::make(['report' => $value], ['report' => [new NoCredentials]]);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('Vaultwarden', $validator->errors()->first('report'));
        $this->assertStringNotContainsString(substr(str_repeat('a1B2', 12), 0, 12), $validator->errors()->first('report'));
    }

    /** @return array<string, array{string}> */
    public static function harmlessText(): array
    {
        return [
            'a rotated password' => ['Rotated the password for the backup user.'],
            'a reference' => ['token = see Vaultwarden'],
            'a reference with a colon' => ['Password: in Vaultwarden'],
            'where it goes' => ['Set API_KEY in .env'],
            'a variable' => ['DB_PASSWORD=${DB_PASSWORD}'],
            'a placeholder' => ['MAIL_PASSWORD=<from vault>'],
            'a user without a password' => ['https://user@host and ssh://git@github.com/acme/shop'],
            'a header in prose' => ['Send it as Authorization: Bearer <token>.'],
            'the token format itself' => ['Tokens start with hb_ followed by 48 characters.'],
            'a library' => ['Installed sk-learn and numpy.'],
            'redis with a variable' => ['redis://:${REDIS_PASSWORD}@cache:6379'],
            'a flag' => ['token: true'],
            'a port' => ['https://host:8443/health'],
            'a short default' => ['SECRET=changeme'],
            'a vault item' => ['API_KEY: 3f8a2c1e-9b7d-4e2a-8c1f-0a1b2c3d4e5f (Vaultwarden item)'],
            'a vault link' => ['DB_PASSWORD: https://vault.example.com/#/vault?itemId=3f8a2c1e-9b7d-4e2a-8c1f-0a1b2c3d4e5f'],
            'words joined by hyphens' => ['Token: rotated-on-2026-11-09'],
            'a name like a key' => ['Scaled sk-prod-cluster-eu-west-1-node-pool-abcdef12 down.'],
            'basic in prose' => ['Basic authentication is off.'],
            'basic in a list' => ['Basic auth/permissions checked.'],
            'a first pass' => ['First pass: app-1,app-2,db-1,db-2'],
            'a working directory' => ['pwd: /var/www/app1/current'],
            'pass inside a word' => ['Compass: /var/www/app1/releases, Bypass: /etc/nginx/sites-enabled/app-1.conf'],
            'passed' => ['Backups passed: 2026-11-09T03:00:00+01:00; smoke tests passed: app-1,app-2,db-1'],
            'a gem' => ['Passenger: 6.0.23-enterprise1'],
            'names of secrets' => ['secrets: db_password_v2,api_token_v3'],
            'an expiry' => ['TOKEN_EXPIRY=2026-12-31T23:59:59Z'],
            'a secret file' => ['DB_PASSWORD_FILE=/run/secrets/app1_db_password'],
            'a token file' => ['token_file=/etc/app-1/token'],
            'a vault path' => ['secret: kv/data/app-1/production'],
            'the shell' => ['PWD=/home/deploy/app-1'],
            'an image digest' => ['Image passed: sha256:'.str_repeat('0a1b', 16)],
        ];
    }

    #[DataProvider('harmlessText')]
    public function test_other_text_passes(string $value): void
    {
        $this->assertTrue(Validator::make(['report' => $value], ['report' => [new NoCredentials]])->passes());
    }

    public function test_a_pattern_that_fails_to_run_refuses(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $this->assertTrue(Validator::make(['report' => 'Rotated the password.'], ['report' => [new NoCredentials]])->fails());
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
    }
}
