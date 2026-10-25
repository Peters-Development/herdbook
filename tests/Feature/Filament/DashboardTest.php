<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\DomainRenewals;
use App\Filament\Widgets\OpenIncidentActions;
use App\Filament\Widgets\OpenIncidents;
use App\Filament\Widgets\ServerRenewals;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_domains_that_need_attention_are_listed_and_can_be_renewed_there(): void
    {
        $this->signIn();
        Date::setTestNow('2027-01-20');
        $due = Domain::factory()->create(['expires_on' => '2027-03-12']);
        $later = Domain::factory()->create(['expires_on' => '2028-03-12']);
        $stopped = Domain::factory()->create(['expires_on' => '2027-02-01', 'renewal_stopped_at' => '2026-12-01 10:00:00']);

        Livewire::test(DomainRenewals::class)
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$later, $stopped])
            ->callAction(TestAction::make('renew')->table($due));

        $this->assertSame('2028-03-12', $due->fresh()->expires_on->toDateString());
    }

    public function test_server_contracts_only_show_up_when_one_needs_attention(): void
    {
        $this->signIn();
        Date::setTestNow('2027-01-20');
        Server::factory()->create(['expires_on' => null]);

        $this->assertFalse(ServerRenewals::canView());

        Server::factory()->create(['expires_on' => '2027-02-01']);
        $this->app->forgetScopedInstances(); // a new request

        $this->assertTrue(ServerRenewals::canView());
    }

    public function test_open_incidents_and_their_actions_are_listed(): void
    {
        $this->signIn();
        $open = Incident::factory()->create();
        $resolved = Incident::factory()->resolved()->create();
        $action = $open->actions()->create(['description' => 'Healer-unit inrichten']);
        $done = $open->actions()->create(['description' => 'Al gedaan', 'done_at' => now()]);

        Livewire::test(OpenIncidents::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$resolved]);

        Livewire::test(OpenIncidentActions::class)
            ->assertCanSeeTableRecords([$action])
            ->assertCanNotSeeTableRecords([$done]);
    }
}
