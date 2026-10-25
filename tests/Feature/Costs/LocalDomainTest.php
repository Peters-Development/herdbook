<?php

namespace Tests\Feature\Costs;

use App\Enums\CheckType;
use App\Enums\CostPeriod;
use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Hostnames\Pages\CreateHostname;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\HostnamesRelationManager;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Provider;
use App\Services\Checks\DefaultChecks;
use App\Services\Costs\RecurringCosts;
use App\Services\Map\InfrastructureMap;
use App\Services\RenewalAttention;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * A domain in the local DNS only, like home.arpa: registered nowhere, so
 * nothing to renew or pay, and every name under it is local.
 */
class LocalDomainTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_a_local_domain_has_nothing_to_renew_or_pay(): void
    {
        $local = Domain::factory()->create([
            'name' => 'home.arpa',
            'local_only' => true,
            'registrar_id' => Provider::factory()->create()->id,
            'cost_amount' => '9.95',
            'cost_period' => CostPeriod::Year,
            'expires_on' => now()->addDays(10)->toDateString(),
        ]);
        Domain::factory()->create(['cost_amount' => null]);

        $local->refresh();
        $this->assertFalse($local->hasContract());
        $this->assertNull($local->registrar_id);
        $this->assertNull($local->cost_amount);
        $this->assertNull($local->expires_on);

        $this->app->forgetScopedInstances();
        $this->assertSame(1, app(RecurringCosts::class)->overview()->missing()[Domain::class]);
        $this->assertSame(1, Domain::query()->missingCost()->count());
        $this->assertSame([], app(RenewalAttention::class)->for(Domain::class)->modelKeys());
    }

    public function test_every_name_under_a_local_domain_is_local(): void
    {
        $local = Domain::factory()->create(['name' => 'home.arpa', 'local_only' => true]);

        $hostname = Hostname::factory()->create(['domain_id' => $local->id, 'fqdn' => 'nas.home.arpa', 'local_only' => false]);

        $this->assertTrue($hostname->fresh()->local_only);
    }

    public function test_marking_a_domain_local_makes_its_names_local_and_their_checks_follow(): void
    {
        $domain = Domain::factory()->create(['name' => 'home.arpa']);
        $hostname = Hostname::factory()->create(['domain_id' => $domain->id, 'fqdn' => 'nas.home.arpa', 'environment_id' => Environment::factory()->create()->id]);
        app(DefaultChecks::class)->createFor($hostname);

        $domain->update(['local_only' => true]);

        $this->assertTrue($hostname->fresh()->local_only);
        $this->assertTrue($hostname->checks()->where('type', CheckType::Http)->sole()->acceptsAnyCertificate());

        // Back to registered: a mistaken toggle is undone by the next.
        $domain->update(['local_only' => false]);

        $this->assertFalse($hostname->fresh()->local_only);
        $this->assertFalse($hostname->checks()->where('type', CheckType::Http)->sole()->acceptsAnyCertificate());
    }

    public function test_a_local_domain_kept_out_of_renewals_even_with_a_date_written_past_the_model(): void
    {
        $local = Domain::factory()->create(['name' => 'home.arpa', 'local_only' => true]);
        Domain::query()->whereKey($local->id)->update(['expires_on' => now()->addDays(5)->toDateString()]);

        $this->assertSame([], app(RenewalAttention::class)->for(Domain::class)->modelKeys());
    }

    public function test_a_local_zone_may_be_a_single_label(): void
    {
        $this->signIn();

        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'internal'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'regex']);

        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'internal', 'local_only' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('internal', Domain::query()->sole()->name);
    }

    public function test_setting_an_amount_in_bulk_skips_local_domains(): void
    {
        $this->signIn();
        $local = Domain::factory()->create(['name' => 'home.arpa', 'local_only' => true]);
        $registered = Domain::factory()->create(['name' => 'example.net', 'cost_amount' => null]);

        Livewire::test(ListDomains::class)
            ->selectTableRecords([$local, $registered])
            ->callAction(TestAction::make('setCost')->table()->bulk(), ['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year->value])
            ->assertNotified(Notification::make()->success()->title('Bedrag ingesteld voor 1 domein: € 9,95 per jaar')->body('1 lokaal domein overgeslagen: dat kost niets.'));

        $this->assertSame('9.95', $registered->fresh()->cost_amount);
        $this->assertNull($local->fresh()->cost_amount);

        Livewire::test(ListDomains::class)
            ->selectTableRecords([$local])
            ->callAction(TestAction::make('setCost')->table()->bulk(), ['cost_amount' => '9.95', 'cost_period' => CostPeriod::Year->value])
            ->assertNotified('1 lokaal domein overgeslagen: dat kost niets.');
    }

    public function test_the_lists_say_local_instead_of_a_renewal(): void
    {
        $this->signIn();
        $local = Domain::factory()->create(['name' => 'home.arpa', 'local_only' => true]);
        $environment = Environment::factory()->create();
        Hostname::factory()->create(['domain_id' => $local->id, 'fqdn' => 'nas.home.arpa', 'environment_id' => $environment->id]);

        Livewire::test(ListDomains::class)
            ->assertTableColumnStateSet('auto_renews', null, $local)
            ->assertTableColumnStateSet('renewal_status', null, $local)
            ->assertSee('Lokaal');

        Livewire::test(HostnamesRelationManager::class, ['ownerRecord' => $environment->project, 'pageClass' => ViewProject::class])
            ->assertTableColumnStateSet('domain_renewal', 'Lokaal', Hostname::query()->sole());
    }

    public function test_the_domain_form_leaves_out_registrar_costs_and_renewal_for_a_local_domain(): void
    {
        $this->signIn();

        Livewire::test(CreateDomain::class)
            ->assertFormFieldVisible('cost_amount')
            ->fillForm(['name' => 'home.arpa', 'local_only' => true])
            ->assertFormFieldHidden('registrar_id')
            ->assertFormFieldHidden('cost_amount')
            ->assertFormFieldHidden('expires_on')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(Domain::query()->sole()->hasContract());
    }

    public function test_the_hostname_form_has_no_local_choice_under_a_local_domain(): void
    {
        $this->signIn();
        $local = Domain::factory()->create(['name' => 'home.arpa', 'local_only' => true]);
        $public = Domain::factory()->create(['name' => 'example.net']);

        Livewire::test(CreateHostname::class)
            ->fillForm(['domain_id' => $public->id])
            ->assertFormFieldVisible('local_only')
            ->fillForm(['domain_id' => $local->id, 'fqdn' => 'nas.home.arpa'])
            ->assertFormFieldHidden('local_only')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hostname::query()->sole()->local_only);
    }

    public function test_the_map_shows_a_local_domain_without_a_renewal_status(): void
    {
        $local = Domain::factory()->create(['name' => 'home.arpa', 'local_only' => true]);
        Hostname::factory()->create(['domain_id' => $local->id, 'fqdn' => 'nas.home.arpa', 'environment_id' => Environment::factory()->create()->id]);

        $node = collect(app(InfrastructureMap::class)->build()['columns']['domains'])->firstWhere('id', "domain-{$local->id}");

        $this->assertSame('Lokaal', $node['sub']);
        $this->assertNull($node['color']);
    }
}
