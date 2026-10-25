<?php

namespace Tests\Feature\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckType;
use App\Filament\RelationManagers\ChecksRelationManager;
use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\ViewCheck;
use App\Filament\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Domains\RelationManagers\HostnamesRelationManager;
use App\Filament\Resources\Hostnames\Pages\ViewHostname;
use App\Models\Check;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Services\Checks\CheckRunner;
use App\Services\Checks\DefaultChecks;
use App\Services\Checks\NetworkProbe;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

/**
 * A name on the local network, like herdbook.home.arpa: in the local DNS
 * only, with a certificate of a CA of its own (Caddy's). Its HTTP check
 * accepts that certificate; it gets no certificate or DNS check.
 */
class LocalHostnameTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, new FakeProbe);
    }

    private function localHostname(array $attributes = []): Hostname
    {
        return Hostname::factory()->create([
            'domain_id' => Domain::factory()->create(['name' => 'home.arpa'])->id,
            'fqdn' => 'herdbook.home.arpa',
            'local_only' => true,
            'environment_id' => Environment::factory()->create()->id,
            ...$attributes,
        ]);
    }

    public function test_an_http_check_that_accepts_any_certificate_does_not_verify_it(): void
    {
        $verify = [];
        Http::fake(function (Request $request, array $options) use (&$verify) {
            $verify[$request->url()] = $options['verify'] ?? 'unset';

            return Http::response('ok');
        });

        $local = Check::factory()->ofType(CheckType::Http, 'https://herdbook.home.arpa/')->create(['settings' => ['accept_any_certificate' => true]]);
        $public = Check::factory()->ofType(CheckType::Keyword, 'https://app.example.net/')->create(['settings' => ['keyword' => 'ok']]);

        $outcomes = app(CheckRunner::class)->run(collect([$local->fresh(), $public->fresh()]));

        $this->assertTrue($outcomes[$local->id]->ok);
        $this->assertTrue($outcomes[$public->id]->ok);
        $this->assertFalse($verify['https://herdbook.home.arpa/']);
        $this->assertTrue($verify['https://app.example.net/']);
    }

    public function test_only_http_and_keyword_checks_accept_any_certificate(): void
    {
        $this->assertTrue(Check::factory()->ofType(CheckType::Keyword, 'https://herdbook.home.arpa/')->make(['settings' => ['accept_any_certificate' => true]])->acceptsAnyCertificate());
        $this->assertFalse(Check::factory()->ofType(CheckType::Http, 'https://herdbook.home.arpa/')->make(['settings' => ['accept_any_certificate' => false]])->acceptsAnyCertificate());
        $this->assertFalse(Check::factory()->ofType(CheckType::Tls, 'herdbook.home.arpa:443')->make(['settings' => ['accept_any_certificate' => true]])->acceptsAnyCertificate());
    }

    public function test_the_page_of_a_check_says_when_it_does_not_verify_the_certificate(): void
    {
        $this->signIn();
        $local = Check::factory()->ofType(CheckType::Http, 'https://herdbook.home.arpa/')->create(['settings' => ['accept_any_certificate' => true]]);
        $public = Check::factory()->ofType(CheckType::Http, 'https://app.example.net/')->create();

        Livewire::test(ViewCheck::class, ['record' => $local->getRouteKey()])->assertSee('Niet gecontroleerd');
        Livewire::test(ViewCheck::class, ['record' => $public->getRouteKey()])->assertDontSee('Niet gecontroleerd');
    }

    public function test_a_local_name_gets_only_a_page_check_that_accepts_its_certificate(): void
    {
        $hostname = $this->localHostname();

        $this->assertSame(1, app(DefaultChecks::class)->createFor($hostname));

        $check = $hostname->checks()->sole();
        $this->assertSame(CheckType::Http, $check->type);
        $this->assertSame('https://herdbook.home.arpa/', $check->target);
        $this->assertSame(['accept_any_certificate' => true], $check->settings);
        $this->assertSame(AlertLevel::Critical, $check->alert_level);
    }

    public function test_a_local_name_that_redirects_checks_the_redirect_and_accepts_its_certificate(): void
    {
        $hostname = $this->localHostname(['fqdn' => 'www.herdbook.home.arpa', 'redirects_to' => 'herdbook.home.arpa']);

        app(DefaultChecks::class)->createFor($hostname);

        $check = $hostname->checks()->sole();
        $this->assertSame(['redirects_to' => 'herdbook.home.arpa', 'accept_any_certificate' => true], $check->settings);
        $this->assertSame(AlertLevel::Normal, $check->alert_level);
    }

    public function test_the_https_checks_of_a_name_follow_when_it_becomes_local_and_back(): void
    {
        $hostname = $this->localHostname(['local_only' => false]);
        app(DefaultChecks::class)->createFor($hostname);
        $byAddress = $hostname->checks()->create(['type' => CheckType::Http, 'target' => 'https://192.168.1.10/up']);
        $plain = $hostname->checks()->create(['type' => CheckType::Keyword, 'target' => 'http://herdbook.home.arpa/', 'settings' => ['keyword' => 'Herdbook']]);
        $https = fn (): Check => $hostname->checks()->where('type', CheckType::Http)->where('target', 'https://herdbook.home.arpa/')->sole();

        $hostname->update(['local_only' => true]);

        $this->assertTrue($https()->acceptsAnyCertificate());
        $this->assertFalse($byAddress->fresh()->acceptsAnyCertificate());
        // Over http:// too: it may redirect to https.
        $this->assertTrue($plain->fresh()->acceptsAnyCertificate());
        // A certificate or DNS check made earlier may be meant: it stays.
        $this->assertEqualsCanonicalizing(['http', 'http', 'keyword', 'tls', 'dns'], $hostname->checks()->where('enabled', true)->pluck('type')->map->value->all());

        $hostname->update(['redirects_to' => 'www.herdbook.home.arpa']);
        $this->assertSame(['accept_any_certificate' => true, 'redirects_to' => 'www.herdbook.home.arpa'], $https()->settings);

        $hostname->update(['local_only' => false]);

        $this->assertSame(['redirects_to' => 'www.herdbook.home.arpa'], $https()->settings);
        $this->assertSame(['keyword' => 'Herdbook'], $plain->fresh()->settings);
    }

    public function test_unmarking_while_renaming_to_a_wildcard_still_verifies_again(): void
    {
        $hostname = $this->localHostname();
        app(DefaultChecks::class)->createFor($hostname);

        $hostname->update(['fqdn' => '*.home.arpa', 'local_only' => false]);

        $check = $hostname->checks()->sole();
        $this->assertFalse($check->enabled);
        $this->assertFalse($check->acceptsAnyCertificate());
    }

    public function test_a_new_check_of_a_local_name_starts_accepting_any_certificate(): void
    {
        $this->signIn();
        $local = $this->localHostname();
        $public = Hostname::factory()->create(['fqdn' => 'app.example.net']);

        Livewire::test(CreateCheck::class)
            ->fillForm(['hostname_id' => $local->id])
            ->assertSchemaStateSet(['settings.accept_any_certificate' => true, 'target' => 'https://herdbook.home.arpa/'])
            ->fillForm(['hostname_id' => $public->id])
            ->assertSchemaStateSet(['settings.accept_any_certificate' => false]);

        Livewire::withQueryParams(['hostname' => (string) $local->id])
            ->test(CreateCheck::class)
            ->assertSchemaStateSet(['settings.accept_any_certificate' => true]);
    }

    public function test_monitoring_a_local_name_from_its_page_says_what_it_creates(): void
    {
        $this->signIn();
        $hostname = $this->localHostname();

        Livewire::test(ViewHostname::class, ['record' => $hostname->getRouteKey()])
            ->assertSee('Alleen op het lokale netwerk')
            ->assertActionExists('enableMonitoring', fn ($action): bool => $action->getModalDescription() === 'Maakt een check aan voor HTTPS die elk certificaat accepteert: een naam op het lokale netwerk krijgt geen certificaat- of DNS-check. Bestaande checks blijven zoals ze zijn.')
            ->callAction('enableMonitoring')
            ->assertNotified('1 check aangemaakt');

        $this->assertSame(1, $hostname->checks()->count());
    }

    public function test_monitoring_a_local_name_that_redirects_says_so_too(): void
    {
        $this->signIn();
        $hostname = $this->localHostname(['fqdn' => 'www.herdbook.home.arpa', 'redirects_to' => 'herdbook.home.arpa']);

        Livewire::test(ViewHostname::class, ['record' => $hostname->getRouteKey()])
            ->assertActionExists('enableMonitoring', fn ($action): bool => $action->getModalDescription() === 'Maakt een check aan voor de doorverwijzing naar herdbook.home.arpa die elk certificaat accepteert: een naam op het lokale netwerk krijgt geen certificaat- of DNS-check. Bestaande checks blijven zoals ze zijn. De doorverwijzing geeft een gewone melding: de noodmelding hoort bij de bewaking van herdbook.home.arpa zelf.');
    }

    public function test_the_checks_tab_of_a_local_name_offers_just_https(): void
    {
        $this->signIn();
        $hostname = $this->localHostname();

        Livewire::test(ChecksRelationManager::class, ['ownerRecord' => $hostname, 'pageClass' => ViewHostname::class])
            ->assertSee('Zet de bewaking aan voor HTTPS.')
            ->assertDontSee('het certificaat en DNS');
    }

    public function test_the_description_of_a_name_on_the_internet_is_unchanged(): void
    {
        $this->signIn();
        $hostname = Hostname::factory()->create();

        Livewire::test(ViewHostname::class, ['record' => $hostname->getRouteKey()])
            ->assertActionExists('enableMonitoring', fn ($action): bool => $action->getModalDescription() === 'Maakt checks aan voor HTTPS, het certificaat en (zonder proxy) de DNS. Bestaande checks blijven zoals ze zijn.');
    }

    public function test_a_hostname_is_marked_as_local_in_its_form(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['name' => 'home.arpa']);

        Livewire::test(HostnamesRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])
            ->callAction(TestAction::make('create')->table(), ['fqdn' => 'herdbook.home.arpa', 'local_only' => true])
            ->assertHasNoFormErrors();

        $this->assertTrue(Hostname::query()->sole()->local_only);
    }

    public function test_the_check_form_offers_accepting_any_certificate_for_http_only(): void
    {
        $this->signIn();
        $hostname = $this->localHostname();

        Livewire::test(CreateCheck::class)
            ->fillForm(['type' => CheckType::Tls])
            ->assertFormFieldHidden('settings.accept_any_certificate')
            ->fillForm([
                'type' => CheckType::Http,
                'hostname_id' => $hostname->id,
                'target' => 'https://herdbook.home.arpa/up',
                'settings' => ['accept_any_certificate' => true],
            ])
            ->assertFormFieldVisible('settings.accept_any_certificate')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Check::query()->sole()->acceptsAnyCertificate());
    }
}
