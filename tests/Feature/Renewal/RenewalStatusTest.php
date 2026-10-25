<?php

namespace Tests\Feature\Renewal;

use App\Enums\RenewalStatus;
use App\Models\Domain;
use App\Models\Provider;
use App\Services\RenewalAttention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RenewalStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Expiry 12-03-2027, renewed automatically 60 days ahead, grace 14 days.
     *
     * @return array<string, array{string, RenewalStatus}>
     */
    public static function automaticRenewalDays(): array
    {
        return [
            'day before the lead time' => ['2027-01-10', RenewalStatus::Active],
            'first day of the lead time' => ['2027-01-11', RenewalStatus::RenewalExpected],
            'last day of the grace period' => ['2027-01-24', RenewalStatus::RenewalExpected],
            'first day after the grace period' => ['2027-01-25', RenewalStatus::NeedsCheck],
            'the expiry date itself' => ['2027-03-12', RenewalStatus::NeedsCheck],
            'day after the expiry date' => ['2027-03-13', RenewalStatus::Expired],
        ];
    }

    #[DataProvider('automaticRenewalDays')]
    public function test_an_automatically_renewing_domain_moves_through_the_statuses(string $today, RenewalStatus $expected): void
    {
        $domain = Domain::factory()->make(['expires_on' => '2027-03-12', 'auto_renews' => true]);

        $this->assertSame($expected, $domain->renewalStatus(Date::parse($today)));
    }

    public function test_a_domain_without_automatic_renewal_asks_for_a_decision_within_the_lead_time(): void
    {
        $domain = Domain::factory()->make(['expires_on' => '2027-03-12', 'auto_renews' => false]);

        $this->assertSame(RenewalStatus::Active, $domain->renewalStatus(Date::parse('2027-01-10')));
        $this->assertSame(RenewalStatus::Decide, $domain->renewalStatus(Date::parse('2027-01-11')));
        $this->assertSame(RenewalStatus::Decide, $domain->renewalStatus(Date::parse('2027-03-12')));
        $this->assertSame(RenewalStatus::Expired, $domain->renewalStatus(Date::parse('2027-03-13')));
    }

    public function test_the_lead_time_comes_from_the_registrar_when_the_domain_has_none(): void
    {
        $registrar = Provider::factory()->registrar()->create(['renewal_lead_days' => 30]);
        $domain = Domain::factory()->create(['expires_on' => '2027-03-12', 'registrar_id' => $registrar->id]);

        $this->assertSame(30, $domain->renewalLeadDays());
        $this->assertSame(RenewalStatus::Active, $domain->renewalStatus(Date::parse('2027-02-09')));
        $this->assertSame(RenewalStatus::RenewalExpected, $domain->renewalStatus(Date::parse('2027-02-10')));
        // grace = min(14, 30 / 2) = 14
        $this->assertSame(RenewalStatus::NeedsCheck, $domain->renewalStatus(Date::parse('2027-02-24')));
    }

    public function test_the_lead_time_of_the_domain_overrides_the_registrar(): void
    {
        $registrar = Provider::factory()->registrar()->create(['renewal_lead_days' => 30]);
        $domain = Domain::factory()->create([
            'expires_on' => '2027-03-12',
            'registrar_id' => $registrar->id,
            'renewal_lead_days' => 90,
        ]);

        $this->assertSame(90, $domain->renewalLeadDays());
    }

    public function test_the_default_lead_time_is_sixty_days(): void
    {
        $this->assertSame(60, Domain::factory()->make()->renewalLeadDays());
    }

    public function test_the_grace_period_is_capped_at_half_a_short_lead_time(): void
    {
        $domain = Domain::factory()->make(['expires_on' => '2027-03-12', 'renewal_lead_days' => 14]);

        // grace = min(14, 14 / 2) = 7: the check starts a week before expiry, not on the day itself
        $this->assertSame(RenewalStatus::RenewalExpected, $domain->renewalStatus(Date::parse('2027-03-04')));
        $this->assertSame(RenewalStatus::NeedsCheck, $domain->renewalStatus(Date::parse('2027-03-05')));
    }

    public function test_a_stopped_domain_is_stopped_even_after_its_expiry_date(): void
    {
        $domain = Domain::factory()->make(['expires_on' => '2027-03-12', 'renewal_stopped_at' => '2027-01-01 10:00:00']);

        $this->assertSame(RenewalStatus::Stopped, $domain->renewalStatus(Date::parse('2027-06-01')));
    }

    public function test_a_domain_without_an_expiry_date_has_no_status(): void
    {
        $domain = Domain::factory()->make(['expires_on' => null]);

        $this->assertSame(RenewalStatus::Unknown, $domain->renewalStatus());
    }

    public function test_today_is_taken_in_the_display_timezone_not_in_utc(): void
    {
        $domain = Domain::factory()->make(['expires_on' => '2027-03-12']);

        // 23:30 UTC on the expiry date is already the next day in Amsterdam.
        Date::setTestNow(Date::parse('2027-03-12 23:30:00', 'UTC'));

        $this->assertSame(RenewalStatus::Expired, $domain->renewalStatus());
    }

    public function test_only_statuses_that_ask_for_action_need_attention(): void
    {
        $attention = array_filter(RenewalStatus::cases(), fn (RenewalStatus $status): bool => $status->needsAttention());

        $this->assertEqualsCanonicalizing(
            [RenewalStatus::RenewalExpected, RenewalStatus::NeedsCheck, RenewalStatus::Decide, RenewalStatus::Expired],
            array_values($attention),
        );
    }

    public function test_a_monthly_contract_is_active_right_after_its_renewal(): void
    {
        // default lead time 60 days > the 31-day term: it is capped at half the term
        $domain = Domain::factory()->make(['expires_on' => '2027-03-31', 'renewal_period_months' => 1]);

        $this->assertSame(15, $domain->effectiveRenewalLeadDays()); // 28-02 → 31-03 is 31 days
        $this->assertSame(RenewalStatus::Active, $domain->renewalStatus(Date::parse('2027-03-01')));
        $this->assertSame(RenewalStatus::RenewalExpected, $domain->renewalStatus(Date::parse('2027-03-17')));
    }

    public function test_the_attention_list_is_computed_once_per_request(): void
    {
        Domain::factory()->count(3)->create(['registrar_id' => Provider::factory()->registrar()->create()->id]);
        $attention = app(RenewalAttention::class);

        DB::enableQueryLog();
        $attention->for(Domain::class);
        $attention->for(Domain::class);
        app(RenewalAttention::class)->for(Domain::class);

        $this->assertCount(2, DB::getQueryLog()); // domains + their registrars, once
    }
}
