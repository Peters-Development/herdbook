<?php

namespace Tests\Feature\Checks;

use App\Enums\CostPeriod;
use App\Models\Domain;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RenewalDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_renewals_that_need_attention_go_out_in_one_message(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake();
        Date::setTestNow('2027-01-30');
        Domain::factory()->create(['name' => 'example.net', 'expires_on' => '2027-03-12']);
        Domain::factory()->create(['expires_on' => '2028-03-12']);

        $this->artisan('renewals:digest')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->header('Title') === ['1 verlenging vraagt aandacht']
            && str_contains($request->body(), 'example.net: Controleren (verloopt 12-03-2027)'));
    }

    public function test_subscriptions_come_along_with_what_they_cost(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake();
        Date::setTestNow('2027-01-30');
        Domain::factory()->create(['name' => 'example.net', 'expires_on' => '2027-03-12', 'cost_amount' => '9.95', 'cost_period' => CostPeriod::Year, 'renewal_period_months' => 24]);
        Subscription::factory()->create(['name' => 'Plausible', 'expires_on' => '2027-02-10', 'auto_renews' => false, 'cost_amount' => '90.00', 'cost_period' => CostPeriod::Year]);
        Subscription::factory()->create(['name' => 'Postmark', 'expires_on' => '2027-02-05', 'renewal_period_months' => 1, 'cost_amount' => '15.00', 'cost_period' => CostPeriod::Month]);

        $this->artisan('renewals:digest')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->header('Title') === ['3 verlengingen vragen aandacht']
            && str_contains($request->body(), "Postmark: Controleren (verloopt 05-02-2027), € 15,00 per maand\n"
                ."Plausible: Beslissen (verloopt 10-02-2027), € 90,00 voor 1 jaar\n"
                .'example.net: Controleren (verloopt 12-03-2027), € 19,90 voor 2 jaar'));
    }

    public function test_an_item_without_an_amount_says_nothing_about_cost(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake();
        Date::setTestNow('2027-01-30');
        Domain::factory()->create(['name' => 'example.net', 'expires_on' => '2027-03-12', 'cost_amount' => null]);

        $this->artisan('renewals:digest')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->body() === 'example.net: Controleren (verloopt 12-03-2027)');
    }

    public function test_nothing_is_sent_when_nothing_needs_attention(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Http::fake();
        Domain::factory()->create(['expires_on' => now()->addYear()->toDateString()]);

        $this->artisan('renewals:digest')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_renewal_digest_goes_out_once_a_week_and_retries_after_a_failure(): void
    {
        config(['herdbook.alerts.ntfy_url' => 'https://ntfy.example/herdbook']);
        Domain::factory()->create(['expires_on' => '2027-03-12']);

        Http::fake(['*' => Http::sequence()->push('', 503)->push('ok')]);

        Date::setTestNow('2027-01-25 08:00:00'); // Monday: ntfy is down
        $this->artisan('renewals:digest')->assertFailed();

        Date::setTestNow('2027-01-26 08:00:00'); // Tuesday: made up for
        $this->artisan('renewals:digest')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->header('Tags') === ['calendar']);

        Date::setTestNow('2027-01-27 08:00:00'); // Wednesday: already sent this week
        $this->artisan('renewals:digest')->expectsOutputToContain('already')->assertSuccessful();
    }
}
