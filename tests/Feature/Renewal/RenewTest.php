<?php

namespace Tests\Feature\Renewal;

use App\Models\Domain;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use LogicException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RenewTest extends TestCase
{
    use RefreshDatabase;

    public function test_renewing_counts_from_the_current_expiry_date_not_from_today(): void
    {
        Date::setTestNow('2027-03-20');
        $domain = Domain::factory()->create(['expires_on' => '2027-03-12']);

        $domain->renew();

        $this->assertSame('2028-03-12', $domain->fresh()->expires_on->toDateString());
    }

    public function test_a_leap_day_becomes_the_last_day_of_february(): void
    {
        $domain = Domain::factory()->create(['expires_on' => '2028-02-29']);

        $domain->renew();

        $this->assertSame('2029-02-28', $domain->fresh()->expires_on->toDateString());
    }

    public function test_renewing_twice_adds_two_periods(): void
    {
        $domain = Domain::factory()->create(['expires_on' => '2027-03-12']);

        $domain->renew();
        $domain->renew();

        $this->assertSame('2029-03-12', $domain->fresh()->expires_on->toDateString());
    }

    public function test_the_renewal_period_is_respected(): void
    {
        $server = Server::factory()->create(['expires_on' => '2027-01-31', 'renewal_period_months' => 1]);

        $server->renew();

        $this->assertSame('2027-02-28', $server->fresh()->expires_on->toDateString());
    }

    public function test_renewing_is_logged_once_with_the_old_and_the_new_date(): void
    {
        $domain = Domain::factory()->create(['expires_on' => '2027-03-12']);
        Activity::query()->delete();

        $domain->renew();

        $activity = Activity::query()->sole();
        $this->assertSame('renewed', $activity->event);
        // The source text, translated when it is shown (ActivityPresenter).
        $this->assertSame('Renewed until :date', $activity->description);
        $this->assertSame('2028-03-12', $activity->properties['date']);
        $this->assertTrue($activity->subject->is($domain));
        $this->assertSame('2027-03-12', $activity->attribute_changes['old']['expires_on']);
        $this->assertSame('2028-03-12', $activity->attribute_changes['attributes']['expires_on']);
    }

    public function test_a_domain_without_an_expiry_date_cannot_be_renewed(): void
    {
        $domain = Domain::factory()->create(['expires_on' => null]);

        $this->assertFalse($domain->canBeRenewed());
        $this->expectException(LogicException::class);

        $domain->renew();
    }

    public function test_a_stopped_domain_cannot_be_renewed_until_it_is_resumed(): void
    {
        $domain = Domain::factory()->create(['expires_on' => '2027-03-12']);
        $domain->stopRenewal();

        $this->assertFalse($domain->fresh()->canBeRenewed());

        $domain->resumeRenewal();

        $this->assertTrue($domain->fresh()->canBeRenewed());
    }
}
