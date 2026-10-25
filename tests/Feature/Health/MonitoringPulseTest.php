<?php

namespace Tests\Feature\Health;

use App\Models\Check;
use App\Services\Checks\MonitoringPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class MonitoringPulseTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_active_checks_nothing_stands_still(): void
    {
        Check::factory()->create(['enabled' => false]);

        $this->assertFalse(app(MonitoringPulse::class)->isStalled());
    }

    public function test_minute_checks_without_a_result_for_five_minutes_stand_still(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        $check = Check::factory()->create(['interval_seconds' => 60]);
        $check->results()->create(['checked_at' => '2026-10-23 11:54:00', 'ok' => true]);

        $this->assertTrue(app(MonitoringPulse::class)->isStalled());

        $check->results()->create(['checked_at' => '2026-10-23 11:57:00', 'ok' => true]);

        $this->assertFalse(app(MonitoringPulse::class)->isStalled());
    }

    public function test_hourly_checks_are_given_their_hour(): void
    {
        Date::setTestNow('2026-10-23 12:00:00');
        $check = Check::factory()->create(['interval_seconds' => 3600]);
        $check->results()->create(['checked_at' => '2026-10-23 11:30:00', 'ok' => true]);

        $this->assertFalse(app(MonitoringPulse::class)->isStalled());

        Date::setTestNow('2026-10-23 12:35:00');

        $this->assertTrue(app(MonitoringPulse::class)->isStalled());
    }
}
