<?php

namespace Tests\Feature\Filament;

use App\Enums\CheckState;
use App\Enums\CheckType;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\RelationManagers\ChecksRelationManager;
use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ViewCheck;
use App\Filament\Resources\Checks\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Hostnames\Pages\ViewHostname;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Widgets\FailingChecks;
use App\Models\Check;
use App\Models\Domain;
use App\Models\Hostname;
use App\Models\Server;
use App\Services\Checks\DefaultChecks;
use App\Services\Checks\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

class MonitoringFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, new FakeProbe);
    }

    public function test_the_check_relation_managers_render(): void
    {
        $this->signIn();
        $check = Check::factory()->create();

        Livewire::test(ChecksRelationManager::class, ['ownerRecord' => $check->hostname, 'pageClass' => ViewHostname::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$check]);
        Livewire::test(ChecksRelationManager::class, ['ownerRecord' => Server::factory()->create(), 'pageClass' => ViewServer::class])
            ->assertOk();
        Livewire::test(EventsRelationManager::class, ['ownerRecord' => $check, 'pageClass' => ViewCheck::class])
            ->assertOk();
    }

    public function test_a_check_is_created_for_a_hostname_with_a_derived_name(): void
    {
        $this->signIn();
        $hostname = Hostname::factory()->create(['fqdn' => 'app.example.net']);

        Livewire::test(CreateCheck::class)
            ->fillForm([
                'type' => CheckType::Keyword,
                'hostname_id' => $hostname->id,
                'target' => 'https://app.example.net/login',
                'settings' => ['keyword' => 'Inloggen'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $check = Check::query()->sole();
        $this->assertSame('HTTP met trefwoord app.example.net', $check->name);
        $this->assertSame('Inloggen', $check->setting('keyword'));
    }

    public function test_the_target_must_fit_the_type(): void
    {
        $this->signIn();
        $server = Server::factory()->create();

        Livewire::test(CreateCheck::class)
            ->fillForm(['type' => CheckType::Http, 'server_id' => $server->id, 'target' => 'app.example.net'])
            ->call('create')
            ->assertHasFormErrors(['target']);

        Livewire::test(CreateCheck::class)
            ->fillForm(['type' => CheckType::Tcp, 'server_id' => $server->id, 'target' => 'db.example.net'])
            ->call('create')
            ->assertHasFormErrors(['target']);
    }

    public function test_a_check_belongs_to_a_hostname_or_a_server_not_both_or_neither(): void
    {
        $this->signIn();
        $hostname = Hostname::factory()->create();
        $server = Server::factory()->create();

        Livewire::test(CreateCheck::class)
            ->fillForm(['type' => CheckType::Ping, 'target' => '203.0.113.10'])
            ->call('create')
            ->assertHasFormErrors(['hostname_id', 'server_id']);

        Livewire::test(CreateCheck::class)
            ->fillForm(['type' => CheckType::Ping, 'hostname_id' => $hostname->id, 'server_id' => $server->id, 'target' => '203.0.113.10'])
            ->call('create')
            ->assertHasFormErrors(['hostname_id']);
    }

    public function test_the_new_check_button_of_a_server_preselects_it(): void
    {
        $this->signIn();
        $server = Server::factory()->create();

        Livewire::withQueryParams(['server' => (string) $server->id])
            ->test(CreateCheck::class)
            ->assertSchemaStateSet(['server_id' => $server->id, 'hostname_id' => null]);
    }

    public function test_running_a_check_now_shows_the_result(): void
    {
        $this->signIn();
        Http::fake(['*' => Http::response('ok')]);
        $check = Check::factory()->create();

        Livewire::test(ViewCheck::class, ['record' => $check->getRouteKey()])
            ->callAction('runNow')
            ->assertNotified();

        $this->assertSame(CheckState::Up, $check->fresh()->state);
        $this->assertSame(1, $check->results()->count());
    }

    public function test_pausing_and_resuming(): void
    {
        $this->signIn();
        $check = Check::factory()->create();

        Livewire::test(ViewCheck::class, ['record' => $check->getRouteKey()])->callAction('pause');
        $this->assertFalse($check->fresh()->enabled);

        Livewire::test(ViewCheck::class, ['record' => $check->getRouteKey()])->callAction('resume');
        $this->assertTrue($check->fresh()->enabled);
    }

    public function test_monitoring_is_switched_on_from_the_hostname_page(): void
    {
        $this->signIn();
        $hostname = Hostname::factory()->create();

        Livewire::test(ViewHostname::class, ['record' => $hostname->getRouteKey()])
            ->callAction('enableMonitoring')
            ->assertNotified('3 checks aangemaakt');

        $this->assertSame(3, $hostname->checks()->count());
    }

    public function test_the_dashboard_lists_what_is_down_and_the_menu_counts_it(): void
    {
        $this->signIn();
        $down = Check::factory()->create();
        $down->forceFill(['state' => CheckState::Down])->save();
        $up = Check::factory()->create();
        $up->forceFill(['state' => CheckState::Up])->save();
        $pausedDown = Check::factory()->create(['enabled' => false]);
        $pausedDown->forceFill(['state' => CheckState::Down])->save();

        Livewire::test(FailingChecks::class)
            ->assertCanSeeTableRecords([$down])
            ->assertCanNotSeeTableRecords([$up, $pausedDown]);

        $this->assertSame('1', CheckResource::getNavigationBadge());
    }

    public function test_the_profile_stores_where_critical_alerts_go(): void
    {
        $user = $this->signIn();

        Livewire::test(EditProfile::class)
            ->fillForm(['pushover_user_key' => str_repeat('a', 30), 'receives_critical_alerts' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(str_repeat('a', 30), $user->fresh()->pushover_user_key);
        $this->assertTrue($user->fresh()->receives_critical_alerts);

        Livewire::test(EditProfile::class)
            ->fillForm(['pushover_user_key' => 'te-kort'])
            ->call('save')
            ->assertHasFormErrors(['pushover_user_key']);
    }

    public function test_a_certificate_target_is_a_name_not_a_url(): void
    {
        $this->signIn();

        Livewire::test(CreateCheck::class)
            ->fillForm(['type' => CheckType::Tls, 'server_id' => Server::factory()->create()->id, 'target' => 'https://app.example.net/'])
            ->call('create')
            ->assertHasFormErrors(['target']);
    }

    public function test_switching_the_type_reshapes_the_target(): void
    {
        $this->signIn();

        Livewire::test(CreateCheck::class)
            ->fillForm(['type' => CheckType::Http, 'target' => 'https://app.example.net/login'])
            ->fillForm(['type' => CheckType::Tls])
            ->assertSchemaStateSet(['target' => 'app.example.net:443', 'success_threshold' => 1]);
    }

    public function test_editing_the_type_keeps_a_chosen_interval(): void
    {
        $this->signIn();
        $check = Check::factory()->create(['interval_seconds' => 900]);

        Livewire::test(EditCheck::class, ['record' => $check->getRouteKey()])
            ->fillForm(['type' => CheckType::Keyword])
            ->assertSchemaStateSet(['interval_seconds' => 900]);
    }

    public function test_a_daily_check_fits(): void
    {
        $this->signIn();

        Livewire::test(CreateCheck::class)
            ->fillForm(['type' => CheckType::Tls, 'server_id' => Server::factory()->create()->id, 'target' => 'app.example.net:443', 'interval_seconds' => 86400])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(86400, Check::query()->sole()->interval_seconds);
    }

    public function test_running_now_while_the_own_line_is_down_says_nothing_was_recorded(): void
    {
        $this->signIn();
        $probe = new FakeProbe;
        $probe->online = false;
        $this->app->instance(NetworkProbe::class, $probe);
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
        $check = Check::factory()->create();

        Livewire::test(ViewCheck::class, ['record' => $check->getRouteKey()])
            ->callAction('runNow')
            ->assertNotified('Niets vastgelegd');
    }

    public function test_a_wildcard_hostname_gets_no_monitoring(): void
    {
        $this->signIn();
        $hostname = Hostname::factory()->create(['fqdn' => '*.example.net', 'domain_id' => Domain::factory()->create(['name' => 'example.net'])->id]);

        $this->assertSame(0, app(DefaultChecks::class)->createFor($hostname));
        Livewire::test(ViewHostname::class, ['record' => $hostname->getRouteKey()])->assertActionHidden('enableMonitoring');
    }

    public function test_a_check_without_recent_results_shows_up_on_the_dashboard(): void
    {
        $this->signIn();
        Date::setTestNow('2026-10-22 10:00:00');
        $stale = Check::factory()->create();
        $stale->forceFill(['state' => CheckState::Up, 'last_checked_at' => '2026-10-22 09:50:00'])->save();
        $fresh = Check::factory()->create();
        $fresh->forceFill(['state' => CheckState::Up, 'last_checked_at' => '2026-10-22 09:59:30'])->save();

        $this->assertTrue($stale->fresh()->isStale());
        Livewire::test(FailingChecks::class)
            ->assertCanSeeTableRecords([$stale])
            ->assertCanNotSeeTableRecords([$fresh]);
    }
}
