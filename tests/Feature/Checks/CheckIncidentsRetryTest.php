<?php

namespace Tests\Feature\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckState;
use App\Enums\IncidentStatus;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\CheckResult;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\IncidentTimelineEntry;
use App\Services\Checks\CheckIncidents;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Date;
use PDOException;
use Tests\TestCase;

/**
 * Draft incidents when a transaction deadlocks and is tried again: outside
 * RefreshDatabase's transaction, as only an outermost one is tried again.
 */
class CheckIncidentsRetryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-22 13:20:00');
    }

    public function test_a_join_tried_again_after_a_deadlock_still_links_the_outage_to_its_incident(): void
    {
        $environment = Environment::factory()->create();
        $this->downCheck($environment);
        $second = $this->downCheck($environment);
        $this->deadlockOnTimelineEntry(2);

        $this->assertSame(1, app(CheckIncidents::class)->openForLongOutages(now()->toImmutable()));

        $incident = Incident::query()->sole();
        $event = CheckEvent::query()->where('check_id', $second->id)->sole();
        $this->assertSame($incident->id, $event->incident_id);
        $this->assertNotNull($event->incident_evaluated_at);
        $this->assertSame(2, $incident->timelineEntries()->count());
    }

    public function test_an_incident_opened_again_after_a_deadlock_is_opened_and_counted_once(): void
    {
        $this->downCheck(Environment::factory()->create());

        // Once the incident is there: as its outage is linked to it.
        $deadlocked = false;
        CheckEvent::updating(function (CheckEvent $event) use (&$deadlocked): void {
            if (! $deadlocked && $event->isDirty('incident_id')) {
                $deadlocked = true;

                throw self::deadlock();
            }
        });

        $this->assertSame(1, app(CheckIncidents::class)->openForLongOutages(now()->toImmutable()));

        $incident = Incident::query()->sole();
        $event = CheckEvent::query()->sole();
        $this->assertSame([1, $incident->id], [$incident->timelineEntries()->count(), $event->incident_id]);
        $this->assertNotNull($event->incident_evaluated_at);
    }

    public function test_a_recovery_tried_again_after_a_deadlock_is_written_once_and_the_incident_watched(): void
    {
        $check = $this->downCheck(Environment::factory()->create());
        app(CheckIncidents::class)->openForLongOutages(now()->toImmutable());
        $check->forceFill(['state' => CheckState::Up])->save();
        $up = $check->events()->create(['from_state' => CheckState::Down, 'to_state' => CheckState::Up, 'occurred_at' => '2026-10-22 13:25:00']);
        $this->deadlockOnTimelineEntry(1);

        app(CheckIncidents::class)->recovered($up);

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentStatus::Monitoring, $incident->status);
        $this->assertSame(1, $incident->timelineEntries()->where('note', 'like', '%'.$check->name)->where('occurred_at', '2026-10-22 13:25:00')->count());
        $this->assertSame(2, $incident->timelineEntries()->count());
    }

    /**
     * The $nth timeline entry made fails once, as a deadlock does.
     */
    private function deadlockOnTimelineEntry(int $nth): void
    {
        $made = 0;

        IncidentTimelineEntry::creating(function () use (&$made, $nth): void {
            if (++$made === $nth) {
                throw self::deadlock();
            }
        });
    }

    private static function deadlock(): QueryException
    {
        return new QueryException('mysql', 'update', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
    }

    private function downCheck(Environment $environment): Check
    {
        $hostname = Hostname::factory()->create(['environment_id' => $environment->id]);
        $check = Check::factory()->create(['hostname_id' => $hostname->id, 'alert_level' => AlertLevel::Critical, 'name' => "HTTP {$hostname->fqdn}"]);
        $check->forceFill(['state' => CheckState::Down])->save();

        foreach (['13:00:00' => true, '13:04:00' => false, '13:05:00' => false, '13:07:00' => false] as $time => $ok) {
            CheckResult::create(['check_id' => $check->id, 'checked_at' => "2026-10-22 {$time}", 'ok' => $ok]);
        }

        $check->events()->create(['from_state' => CheckState::Pending, 'to_state' => CheckState::Down, 'occurred_at' => '2026-10-22 13:07:00', 'error' => 'HTTP 503']);

        return $check;
    }
}
