<?php

namespace Tests\Feature\Filament;

use App\Enums\ProviderKind;
use App\Enums\RenewalStatus;
use App\Enums\ServerStatus;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Domains\RelationManagers\HostnamesRelationManager;
use App\Filament\Resources\Hostnames\HostnameResource;
use App\Filament\Resources\Hostnames\Pages\CreateHostname;
use App\Filament\Resources\Providers\Pages\ListProviders;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class DomainFlowTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_renewing_from_the_table_moves_the_expiry_date_one_term(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['expires_on' => '2027-03-12']);

        Livewire::test(ListDomains::class)
            ->callAction(TestAction::make('renew')->table($domain));

        $this->assertSame('2028-03-12', $domain->fresh()->expires_on->toDateString());
    }

    public function test_renewing_from_the_view_page_works_too(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['expires_on' => '2027-03-12']);

        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->callAction('renew');

        $this->assertSame('2028-03-12', $domain->fresh()->expires_on->toDateString());
    }

    public function test_a_bulk_renewal_skips_what_cannot_be_renewed(): void
    {
        $this->signIn();
        $first = Domain::factory()->create(['expires_on' => '2027-03-12']);
        $second = Domain::factory()->create(['expires_on' => '2027-06-01']);
        $undated = Domain::factory()->create(['expires_on' => null]);

        Livewire::test(ListDomains::class)
            ->selectTableRecords([$first, $second, $undated])
            ->callAction(TestAction::make('renew')->table()->bulk());

        $this->assertSame('2028-03-12', $first->fresh()->expires_on->toDateString());
        $this->assertSame('2028-06-01', $second->fresh()->expires_on->toDateString());
        $this->assertNull($undated->fresh()->expires_on);
    }

    public function test_the_renew_action_is_hidden_without_an_expiry_date(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['expires_on' => null]);

        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertActionHidden('renew');
    }

    public function test_the_attention_filter_shows_only_domains_that_need_action(): void
    {
        $this->signIn();
        Date::setTestNow('2027-01-20');
        $due = Domain::factory()->create(['expires_on' => '2027-03-12']);
        $later = Domain::factory()->create(['expires_on' => '2028-03-12']);

        Livewire::test(ListDomains::class)
            ->filterTable('needs_attention')
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$later]);
    }

    public function test_a_hostname_must_belong_to_its_domain(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['name' => 'example.net']);
        $parameters = ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class];

        Livewire::test(HostnamesRelationManager::class, $parameters)
            ->callAction(TestAction::make('create')->table(), ['fqdn' => 'app.anderdomein.test'])
            ->assertHasFormErrors(['fqdn']);

        Livewire::test(HostnamesRelationManager::class, $parameters)
            ->callAction(TestAction::make('create')->table(), ['fqdn' => 'App.Example.net'])
            ->assertHasNoFormErrors();

        $this->assertSame('app.example.net', Hostname::query()->sole()->fqdn);
    }

    public function test_an_environment_is_found_by_its_project_among_many(): void
    {
        $this->signIn();
        Environment::factory()->count(60)->create();
        $wanted = Environment::factory()->create(['project_id' => Project::factory()->create(['name' => 'Acme Platform'])->id]);

        // all options, labelled with the project, so the browser's search finds it
        $options = Livewire::test(CreateHostname::class)
            ->instance()
            ->form
            ->getComponent(fn ($component): bool => $component instanceof Select && $component->getName() === 'environment_id')
            ->getOptions();

        $this->assertCount(61, $options);
        $this->assertSame('Acme Platform · Production', $options[$wanted->id]);
    }

    public function test_unticking_a_kind_does_not_break_records_that_use_the_provider(): void
    {
        $this->signIn();
        $provider = Provider::factory()->create(['kinds' => [ProviderKind::Registrar->value, ProviderKind::Dns->value]]);
        $domain = Domain::factory()->create(['dns_provider_id' => $provider->id, 'registrar_id' => $provider->id]);
        $provider->update(['kinds' => [ProviderKind::Registrar->value]]);

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->fillForm(['notes' => 'Alleen een notitie'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_status_filter_narrows_to_one_computed_status(): void
    {
        $this->signIn();
        Date::setTestNow('2027-01-30');
        $needsCheck = Domain::factory()->create(['expires_on' => '2027-03-12']);
        $active = Domain::factory()->create(['expires_on' => '2028-03-12']);

        Livewire::test(ListDomains::class)
            ->filterTable('renewal_status', RenewalStatus::NeedsCheck->value)
            ->assertCanSeeTableRecords([$needsCheck])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_a_url_is_not_a_hostname(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['name' => 'example.net']);

        Livewire::test(HostnamesRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])
            ->callAction(TestAction::make('create')->table(), ['fqdn' => 'https://app.example.net'])
            ->assertHasFormErrors(['fqdn']);
    }

    public function test_a_wildcard_hostname_is_allowed(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['name' => 'example.net']);

        Livewire::test(HostnamesRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])
            ->callAction(TestAction::make('create')->table(), ['fqdn' => '*.example.net'])
            ->assertHasNoFormErrors();
    }

    public function test_the_mail_server_is_chosen_on_the_domain_and_shown_on_both_sides(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['name' => 'example.com']);
        $mail = Server::factory()->create(['name' => 'mail-1']);

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->fillForm(['mail_server_id' => $mail->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($domain->fresh()->mailServer->is($mail));

        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee('Mailserver')
            ->assertSee('mail-1');

        $this->get(ServerResource::getUrl('view', ['record' => $mail]))
            ->assertOk()
            ->assertSee('Mail van')
            ->assertSee('example.com')
            // only mail runs here: no "nothing runs here" next to it
            ->assertDontSee('Er draait niets op deze server.');
    }

    public function test_a_mail_server_that_does_not_exist_is_refused(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create();

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->fillForm(['mail_server_id' => 999])
            ->call('save')
            ->assertHasFormErrors(['mail_server_id']);

        $this->assertNull($domain->fresh()->mail_server_id);
    }

    public function test_a_bulk_action_sets_and_clears_the_mail_server_of_many_domains(): void
    {
        $this->signIn();
        $mail = Server::factory()->create();
        $domains = Domain::factory()->count(3)->create();
        $other = Domain::factory()->create();

        Livewire::test(ListDomains::class)
            ->selectTableRecords($domains)
            ->callAction(TestAction::make('setMailServer')->table()->bulk(), ['mail_server_id' => $mail->id])
            ->assertNotified('Mailserver ingesteld voor 3 domeinen.');

        $this->assertSame(3, $mail->mailDomains()->count());
        $this->assertNull($other->fresh()->mail_server_id);

        Livewire::test(ListDomains::class)
            ->selectTableRecords([$domains->first()])
            ->callAction(TestAction::make('setMailServer')->table()->bulk(), ['mail_server_id' => null]);

        $this->assertNull($domains->first()->fresh()->mail_server_id);
        $this->assertSame(2, $mail->mailDomains()->count());
    }

    public function test_a_server_out_of_use_is_not_offered_unless_it_already_is_the_mail_server(): void
    {
        $this->signIn();
        $old = Server::factory()->create(['name' => 'old-mail', 'status' => ServerStatus::Decommissioned]);
        $domain = Domain::factory()->create();

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->fillForm(['mail_server_id' => $old->id])
            ->call('save')
            ->assertHasFormErrors(['mail_server_id']);

        $kept = Domain::factory()->create(['mail_server_id' => $old->id]);

        Livewire::test(EditDomain::class, ['record' => $kept->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ViewDomain::class, ['record' => $kept->getRouteKey()])
            ->assertSee('old-mail (Uit gebruik)');
    }

    public function test_the_dns_server_is_chosen_on_the_domain_and_its_provider_becomes_the_dns_host(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['name' => 'example.com', 'dns_provider_id' => Provider::factory()->registrar()->create(['name' => 'Old DNS'])->id]);
        // A hosting provider, not one offering DNS: its package runs the DNS all the same.
        $package = Server::factory()->for(Provider::factory()->create(['name' => 'Packager']))->create(['name' => 'package-1']);

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->assertFormFieldVisible('dns_provider_id')
            ->fillForm(['dns_server_id' => $package->id])
            ->assertFormFieldHidden('dns_provider_id')
            ->assertSee('DNS bij: Packager, de leverancier van deze server.')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($domain->fresh()->dnsServer->is($package));
        $this->assertSame($package->provider_id, $domain->fresh()->dns_provider_id);

        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee('DNS-server')
            ->assertSee('package-1')
            ->assertSee('Packager')
            ->assertSee(ProviderResource::getUrl('view', ['record' => $package->provider_id]));

        $this->get(ServerResource::getUrl('view', ['record' => $package]))
            ->assertOk()
            ->assertSee('DNS van')
            ->assertSee('example.com')
            // only DNS runs here: no "nothing runs here" next to it
            ->assertDontSee('Er draait niets op deze server.');
    }

    public function test_a_new_domain_with_its_dns_on_a_server_without_provider_has_no_dns_host(): void
    {
        $this->signIn();
        $box = Server::factory()->create(['provider_id' => null]);

        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'example.org', 'dns_provider_id' => Provider::factory()->registrar()->create()->id, 'dns_server_id' => $box->id])
            ->assertSee('DNS bij: niemand, deze server heeft geen leverancier.')
            ->call('create')
            ->assertHasNoFormErrors();

        $domain = Domain::query()->where('name', 'example.org')->sole();
        $this->assertSame($box->id, $domain->dns_server_id);
        $this->assertNull($domain->dns_provider_id);

        // Not "no DNS" anywhere: the server itself serves it.
        $hostname = Hostname::factory()->for($domain)->create();

        Livewire::test(ListDomains::class)->assertTableColumnStateSet('dns_host', $box->name, $domain);

        $this->get(HostnameResource::getUrl('view', ['record' => $hostname]))
            ->assertOk()
            ->assertSee(e($box->name), false)
            ->assertSee(ServerResource::getUrl('view', ['record' => $box]));
    }

    public function test_clearing_the_dns_server_right_after_choosing_it_keeps_the_new_dns_host(): void
    {
        $this->signIn();
        $package = Server::factory()->for(Provider::factory())->create();
        $domain = Domain::factory()->create(['dns_provider_id' => Provider::factory()->registrar()->create()->id]);

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->fillForm(['dns_server_id' => $package->id])
            ->call('save')
            ->fillForm(['dns_server_id' => null])
            ->assertFormSet(['dns_provider_id' => $package->provider_id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($package->provider_id, $domain->fresh()->dns_provider_id);
    }

    public function test_clearing_the_dns_server_in_the_form_shows_the_dns_host_again_and_keeps_it(): void
    {
        $this->signIn();
        $package = Server::factory()->for(Provider::factory())->create();
        $domain = Domain::factory()->create(['dns_server_id' => $package->id]);

        Livewire::test(EditDomain::class, ['record' => $domain->getRouteKey()])
            ->assertFormFieldHidden('dns_provider_id')
            ->fillForm(['dns_server_id' => null])
            ->assertFormFieldVisible('dns_provider_id')
            ->assertFormSet(['dns_provider_id' => $package->provider_id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($domain->fresh()->dns_server_id);
        $this->assertSame($package->provider_id, $domain->fresh()->dns_provider_id);
    }

    public function test_a_bulk_action_sets_the_dns_server_and_with_it_the_dns_host(): void
    {
        $this->signIn();
        $package = Server::factory()->for(Provider::factory())->create();
        $domains = Domain::factory()->count(2)->create();
        $other = Domain::factory()->create();

        Livewire::test(ListDomains::class)
            ->selectTableRecords($domains)
            ->callAction(TestAction::make('setDnsServer')->table()->bulk(), ['dns_server_id' => $package->id])
            ->assertNotified('DNS-server ingesteld voor 2 domeinen.');

        $this->assertSame(2, $package->dnsDomains()->where('dns_provider_id', $package->provider_id)->count());
        $this->assertNull($other->fresh()->dns_server_id);

        Livewire::test(ListDomains::class)
            ->selectTableRecords([$domains->first()])
            ->callAction(TestAction::make('setDnsServer')->table()->bulk(), ['dns_server_id' => null]);

        // The server goes, the DNS host stays.
        $this->assertNull($domains->first()->fresh()->dns_server_id);
        $this->assertSame($package->provider_id, $domains->first()->fresh()->dns_provider_id);
    }

    public function test_the_list_shows_and_filters_on_the_dns_host(): void
    {
        $this->signIn();
        $cloud = Provider::factory()->registrar()->create(['name' => 'Cloudy']);
        $atCloud = Domain::factory()->create(['dns_provider_id' => $cloud->id]);
        $elsewhere = Domain::factory()->create();

        Livewire::test(ListDomains::class)
            ->assertTableColumnStateSet('dns_host', 'Cloudy', $atCloud)
            ->filterTable('dns_provider_id', $cloud->id)
            ->assertCanSeeTableRecords([$atCloud])
            ->assertCanNotSeeTableRecords([$elsewhere]);

        $package = Server::factory()->create(['name' => 'package-1']);
        $onPackage = Domain::factory()->create(['dns_server_id' => $package->id]);

        Livewire::test(ListDomains::class)
            ->assertTableColumnStateSet('dnsServer.name', 'package-1', $onPackage)
            ->filterTable('dns_server_id', $package->id)
            ->assertCanSeeTableRecords([$onPackage])
            ->assertCanNotSeeTableRecords([$atCloud, $elsewhere]);

        // The link on the provider page opens the same list.
        Livewire::withQueryParams(['filters' => ['dns_provider_id' => ['value' => $cloud->id]]])
            ->test(ListDomains::class)
            ->assertCanSeeTableRecords([$atCloud])
            ->assertCanNotSeeTableRecords([$elsewhere]);
    }

    public function test_the_provider_page_links_its_dns_domains_and_a_hostname_names_its_dns_host(): void
    {
        $this->signIn();
        $cloud = Provider::factory()->registrar()->create(['name' => 'Cloudy']);
        $domain = Domain::factory()->create(['dns_provider_id' => $cloud->id]);
        $hostname = Hostname::factory()->for($domain)->create();

        Domain::factory()->create(['registrar_id' => $cloud->id]);
        Server::factory()->for($cloud)->create();

        $this->get(ProviderResource::getUrl('view', ['record' => $cloud]))
            ->assertOk()
            ->assertSee('DNS-domeinen')
            ->assertSee('Geregistreerde domeinen')
            ->assertSee(e(DomainResource::getUrl('index', ['filters' => ['dns_provider_id' => ['value' => $cloud->id]]])), false)
            ->assertSee(e(DomainResource::getUrl('index', ['filters' => ['registrar_id' => ['value' => $cloud->id]]])), false)
            ->assertSee(e(ServerResource::getUrl('index', ['filters' => ['provider_id' => ['value' => $cloud->id]]])), false);

        // Nothing behind a zero: no link to an empty list, no DNS count at all.
        $idle = Provider::factory()->create();
        $this->get(ProviderResource::getUrl('view', ['record' => $idle]))
            ->assertOk()
            ->assertDontSee('DNS-domeinen')
            ->assertDontSee(e(ServerResource::getUrl('index', ['filters' => ['provider_id' => ['value' => $idle->id]]])), false);

        Livewire::test(ListProviders::class)
            ->assertTableColumnStateSet('dns_domains_count', 1, $cloud)
            ->assertTableColumnStateSet('registered_domains_count', 1, $cloud);

        $this->get(HostnameResource::getUrl('view', ['record' => $hostname]))
            ->assertOk()
            ->assertSee('DNS bij')
            ->assertSee('Cloudy')
            ->assertSee(ProviderResource::getUrl('view', ['record' => $cloud]));
    }
}
