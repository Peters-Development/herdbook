<?php

namespace Tests\Feature\Checks;

use App\Enums\CheckType;
use App\Enums\ServerRole;
use App\Models\Check;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Server;
use App\Services\Checks\CheckOutcome;
use App\Services\Checks\CheckRunner;
use App\Services\Checks\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeProbe;
use Tests\Fakes\ThrowingProbe;
use Tests\TestCase;

class CheckRunnerTest extends TestCase
{
    use RefreshDatabase;

    private FakeProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, $this->probe = new FakeProbe);
    }

    private function outcomeOf(Check $check): CheckOutcome
    {
        return app(CheckRunner::class)->run(collect([$check->fresh(['hostname.environment', 'server'])]))[$check->id];
    }

    private function httpCheck(array $settings = [], CheckType $type = CheckType::Http): Check
    {
        return Check::factory()->ofType($type, 'https://app.example.net/')->create(['settings' => $settings]);
    }

    public function test_an_http_check_is_up_with_a_2xx_status(): void
    {
        Http::fake(['app.example.net/*' => Http::response('ok', 200)]);

        $outcome = $this->outcomeOf($this->httpCheck());

        $this->assertTrue($outcome->ok);
        $this->assertSame(200, $outcome->statusCode);
    }

    public function test_an_http_error_status_is_down(): void
    {
        Http::fake(['app.example.net/*' => Http::response('kapot', 503)]);

        $outcome = $this->outcomeOf($this->httpCheck());

        $this->assertFalse($outcome->ok);
        $this->assertSame('HTTP 503', $outcome->error);
    }

    public function test_an_expected_status_must_match_exactly(): void
    {
        Http::fake(['app.example.net/*' => Http::response('', 200)]);

        $outcome = $this->outcomeOf($this->httpCheck(['expected_status' => 401]));

        $this->assertSame('HTTP 200, verwacht 401', $outcome->error);
    }

    public function test_a_keyword_check_looks_for_the_word_in_the_page(): void
    {
        Http::fake(['app.example.net/*' => Http::response('<h1>Inloggen</h1>', 200)]);

        $this->assertTrue($this->outcomeOf($this->httpCheck(['keyword' => 'inloggen'], CheckType::Keyword))->ok);
        $this->assertSame('Trefwoord "Dashboard" niet gevonden', $this->outcomeOf($this->httpCheck(['keyword' => 'Dashboard'], CheckType::Keyword))->error);
    }

    public function test_no_answer_is_down_with_a_short_reason(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

        $this->assertSame('Geen antwoord: time-out', $this->outcomeOf($this->httpCheck())->error);
    }

    public function test_a_certificate_is_down_when_it_expires_within_the_warning_days(): void
    {
        Date::setTestNow('2026-10-22 12:00:00');
        $check = Check::factory()->ofType(CheckType::Tls, 'app.example.net:443')->create(['settings' => ['warn_days' => 14]]);

        $this->probe->certificates['app.example.net'] = Date::parse('2026-12-01');
        $this->assertTrue($this->outcomeOf($check)->ok);

        $this->probe->certificates['app.example.net'] = Date::parse('2026-10-27 12:00:00');
        $this->assertSame('Certificaat verloopt over 5 dagen (27-10-2026)', $this->outcomeOf($check)->error);

        $this->probe->certificates['app.example.net'] = Date::parse('2026-10-20');
        $this->assertStringStartsWith('Certificaat verlopen op', $this->outcomeOf($check)->error);
    }

    public function test_the_reason_is_in_the_active_language(): void
    {
        Date::setTestNow('2026-10-22 12:00:00');
        $check = Check::factory()->ofType(CheckType::Tls, 'app.example.net:443')->create(['settings' => ['warn_days' => 14]]);
        $this->probe->certificates['app.example.net'] = Date::parse('2026-10-23 12:00:00');

        $this->assertSame('Certificaat verloopt over 1 dag (23-10-2026)', $this->outcomeOf($check)->error);

        app()->setLocale('en');

        $this->assertSame('Certificate expires in 1 day (23 Oct 2026)', $this->outcomeOf($check)->error);

        $this->probe->certificates['app.example.net'] = Date::parse('2026-10-27 12:00:00');
        $this->assertSame('Certificate expires in 5 days (27 Oct 2026)', $this->outcomeOf($check)->error);

        Http::fake(['app.example.net/*' => Http::response('', 503)]);
        $this->assertSame('HTTP 503, expected 200', $this->outcomeOf($this->httpCheck(['expected_status' => 200]))->error);
    }

    public function test_a_tcp_check_needs_a_port_and_an_open_one(): void
    {
        $this->assertSame('Geen poort in het doel (host:poort).', $this->outcomeOf(Check::factory()->ofType(CheckType::Tcp, 'db.example.net')->create())->error);

        $this->probe->closedPorts['db.example.net:3306'] = 'Poort 3306 op db.example.net niet bereikbaar';
        $this->assertFalse($this->outcomeOf(Check::factory()->ofType(CheckType::Tcp, 'db.example.net:3306')->create())->ok);

        $this->assertTrue($this->outcomeOf(Check::factory()->ofType(CheckType::Tcp, '[2001:db8::1]:22')->create())->ok);
        $this->assertContains('2001:db8::1:22', $this->probe->connections);
    }

    public function test_dns_must_point_at_the_app_servers_of_the_environment(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'app.example.net']);
        $environment = Environment::factory()->create();
        $hostname->update(['environment_id' => $environment->id]);
        $server = Server::factory()->create(['public_ipv4' => '203.0.113.10']);
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        $check = Check::factory()->ofType(CheckType::Dns, 'app.example.net')->create(['hostname_id' => $hostname->id]);

        $this->probe->addresses['app.example.net'] = ['203.0.113.10'];
        $this->assertTrue($this->outcomeOf($check)->ok);

        $this->probe->addresses['app.example.net'] = ['198.51.100.7'];
        $this->assertSame('Wijst naar 198.51.100.7, verwacht 203.0.113.10', $this->outcomeOf($check)->error);
    }

    public function test_expected_ips_on_the_hostname_override_the_servers(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'app.example.net', 'expected_ips' => ['198.51.100.7']]);
        $check = Check::factory()->ofType(CheckType::Dns, 'app.example.net')->create(['hostname_id' => $hostname->id]);
        $this->probe->addresses['app.example.net'] = ['198.51.100.7'];

        $this->assertTrue($this->outcomeOf($check)->ok);
    }

    public function test_behind_a_proxy_any_answer_will_do(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'app.example.net', 'behind_proxy' => true, 'expected_ips' => ['203.0.113.10']]);
        $check = Check::factory()->ofType(CheckType::Dns, 'app.example.net')->create(['hostname_id' => $hostname->id]);
        $this->probe->addresses['app.example.net'] = ['104.16.0.1'];

        $this->assertTrue($this->outcomeOf($check)->ok);

        unset($this->probe->addresses['app.example.net']);
        $this->assertSame('app.example.net heeft geen A- of AAAA-record.', $this->outcomeOf($check)->error);
    }

    public function test_ping(): void
    {
        $check = Check::factory()->forServer()->create(['target' => '203.0.113.10']);

        $this->assertTrue($this->outcomeOf($check)->ok);

        $this->probe->unreachable[] = '203.0.113.10';
        $this->assertFalse($this->outcomeOf($check)->ok);
    }

    public function test_a_probe_that_throws_is_one_check_down_not_a_lost_batch(): void
    {
        $this->app->instance(NetworkProbe::class, new ThrowingProbe);
        $dns = Check::factory()->ofType(CheckType::Dns, 'app.example.net')->create();
        $ping = Check::factory()->forServer()->create();

        $outcomes = app(CheckRunner::class)->run(collect([$dns, $ping]));

        $this->assertSame('De controle zelf mislukte.', $outcomes[$dns->id]->error);
        $this->assertTrue($outcomes[$ping->id]->ok);
    }

    public function test_dns_compares_one_notation_per_address_and_only_the_expected_families(): void
    {
        $environment = Environment::factory()->create();
        $hostname = Hostname::factory()->create(['fqdn' => 'app.example.net', 'environment_id' => $environment->id]);
        $server = Server::factory()->create(['public_ipv4' => '203.0.113.10', 'public_ipv6' => '2001:DB8:0::1']);
        EnvironmentServer::create(['environment_id' => $environment->id, 'server_id' => $server->id, 'role' => ServerRole::App]);
        $check = Check::factory()->ofType(CheckType::Dns, 'app.example.net')->create(['hostname_id' => $hostname->id]);

        $this->probe->addresses['app.example.net'] = ['203.0.113.10', '2001:db8::1'];
        $this->assertTrue(app(CheckRunner::class)->runOne($check->fresh(['hostname']))->ok);

        $server->update(['public_ipv6' => null]);
        $this->probe->addresses['app.example.net'] = ['203.0.113.10', '2001:db8::99'];
        $this->assertTrue(app(CheckRunner::class)->runOne($check->fresh(['hostname']))->ok);
    }

    public function test_an_invalid_url_is_down_on_its_own_without_taking_the_batch_with_it(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $bad = Check::factory()->ofType(CheckType::Http, 'https://app.example.net:99999/')->create();
        $good = Check::factory()->ofType(CheckType::Http, 'https://app.example.net/')->create();

        $outcomes = app(CheckRunner::class)->runHttp(collect([$bad, $good]));

        $this->assertSame('Ongeldige URL.', $outcomes[$bad->id]->error);
        $this->assertTrue($outcomes[$good->id]->ok);
    }

    public function test_urls_with_a_bad_port_or_credentials_are_not_valid_targets(): void
    {
        $this->assertFalse(Check::isValidHttpTarget('https://app.example.net:99999/'));
        $this->assertFalse(Check::isValidHttpTarget('https://admin:geheim@app.example.net/'));
        $this->assertFalse(Check::isValidHttpTarget('ftp://app.example.net/'));
        $this->assertTrue(Check::isValidHttpTarget('https://app.example.net:8443/login?x=1'));
    }
}
