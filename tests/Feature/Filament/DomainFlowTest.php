<?php

namespace Tests\Feature\Filament;

use App\Enums\ProviderKind;
use App\Enums\RenewalStatus;
use App\Enums\ServerStatus;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Domains\RelationManagers\HostnamesRelationManager;
use App\Filament\Resources\Hostnames\Pages\CreateHostname;
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
}
