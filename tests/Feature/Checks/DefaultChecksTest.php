<?php

namespace Tests\Feature\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckType;
use App\Models\Environment;
use App\Models\Hostname;
use App\Services\Checks\DefaultChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_production_hostname_gets_https_certificate_and_dns_checks(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'app.example.net', 'environment_id' => Environment::factory()->create()->id]);

        $this->assertSame(3, app(DefaultChecks::class)->createFor($hostname));

        $checks = $hostname->checks()->get()->keyBy(fn ($check) => $check->type->value);
        $this->assertSame('https://app.example.net/', $checks['http']->target);
        $this->assertSame(AlertLevel::Critical, $checks['http']->alert_level);
        $this->assertSame('app.example.net:443', $checks['tls']->target);
        $this->assertSame(21600, $checks['tls']->interval_seconds);
        $this->assertSame(2, $checks['dns']->failure_threshold);
        $this->assertSame(1, $checks['tls']->success_threshold);
    }

    public function test_behind_a_proxy_there_is_no_dns_check_and_staging_is_not_critical(): void
    {
        $hostname = Hostname::factory()->create(['behind_proxy' => true, 'environment_id' => Environment::factory()->staging()->create()->id]);

        app(DefaultChecks::class)->createFor($hostname);

        $this->assertEqualsCanonicalizing(['http', 'tls'], $hostname->checks()->pluck('type')->map->value->all());
        $this->assertSame(AlertLevel::Normal, $hostname->checks()->where('type', CheckType::Http)->sole()->alert_level);
    }

    public function test_running_it_twice_creates_nothing_twice(): void
    {
        $hostname = Hostname::factory()->create();

        app(DefaultChecks::class)->createFor($hostname);

        $this->assertSame(0, app(DefaultChecks::class)->createFor($hostname));
        $this->assertSame(3, $hostname->checks()->count());
    }
}
