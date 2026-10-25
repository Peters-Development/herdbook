<?php

namespace Tests\Feature\Checks;

use App\Enums\AlertLevel;
use App\Enums\CheckType;
use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Domains\RelationManagers\HostnamesRelationManager;
use App\Filament\Resources\Hostnames\Pages\ViewHostname;
use App\Models\Check;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Hostname;
use App\Services\Checks\CheckOutcome;
use App\Services\Checks\CheckRunner;
use App\Services\Checks\DefaultChecks;
use App\Services\Checks\NetworkProbe;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SignsIn;
use Tests\Fakes\FakeProbe;
use Tests\TestCase;

/**
 * "Redirects to": a name like www.example.net that only sends visitors
 * on. Its HTTP check checks the redirect instead of following it.
 */
class RedirectCheckTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NetworkProbe::class, new FakeProbe);
    }

    private function redirectCheck(array $settings = []): Check
    {
        return Check::factory()
            ->ofType(CheckType::Http, 'https://www.example.net/')
            ->create(['settings' => ['redirects_to' => 'example.net', ...$settings]]);
    }

    private function outcomeOf(Check $check): CheckOutcome
    {
        return app(CheckRunner::class)->runOne($check->fresh());
    }

    public function test_a_redirect_to_the_expected_name_is_up_without_following_it(): void
    {
        Http::fake([
            'https://www.example.net/*' => Http::response('', 301, ['Location' => 'https://Example.NET./']),
            // Following it would make the check depend on the site itself.
            'https://example.net/*' => Http::response('kapot', 500),
        ]);

        $outcome = $this->outcomeOf($this->redirectCheck());

        $this->assertTrue($outcome->ok);
        $this->assertSame(301, $outcome->statusCode);
    }

    public function test_a_page_instead_of_a_redirect_is_down(): void
    {
        Http::fake(['https://www.example.net/*' => Http::response('ok', 200)]);

        $outcome = $this->outcomeOf($this->redirectCheck());

        $this->assertFalse($outcome->ok);
        $this->assertSame('HTTP 200, verwacht een doorverwijzing naar example.net', $outcome->error);
    }

    public function test_a_redirect_elsewhere_is_down_and_says_where(): void
    {
        Http::fake(['https://www.example.net/*' => Http::response('', 302, ['Location' => 'https://parked.example.org/?d=example'])]);

        $this->assertSame('Stuurt door naar parked.example.org, verwacht example.net', $this->outcomeOf($this->redirectCheck())->error);
    }

    public function test_a_relative_redirect_stays_on_the_same_name(): void
    {
        Http::fake(['https://www.example.net/*' => Http::response('', 301, ['Location' => '/nl/'])]);

        $this->assertSame('Stuurt door naar www.example.net, verwacht example.net', $this->outcomeOf($this->redirectCheck())->error);
    }

    public function test_a_redirect_without_a_location_is_down(): void
    {
        Http::fake(['https://www.example.net/*' => Http::response('', 301)]);

        $this->assertSame('HTTP 301 zonder Location', $this->outcomeOf($this->redirectCheck())->error);
    }

    public function test_an_expected_status_must_match_too(): void
    {
        Http::fake(['https://www.example.net/*' => Http::response('', 302, ['Location' => 'https://example.net/'])]);

        $this->assertSame('HTTP 302, verwacht 301', $this->outcomeOf($this->redirectCheck(['expected_status' => 301]))->error);
        $this->assertTrue($this->outcomeOf($this->redirectCheck(['expected_status' => 302]))->ok);
    }

    public function test_a_location_that_is_no_url_is_down(): void
    {
        Http::fake(['https://www.example.net/*' => Http::response('', 301, ['Location' => 'https://example.net:99999/'])]);

        $this->assertSame('Ongeldige Location: https://example.net:99999/', $this->outcomeOf($this->redirectCheck())->error);
    }

    public function test_monitoring_a_redirecting_name_checks_the_redirect_and_pages_nobody(): void
    {
        $hostname = Hostname::factory()->create([
            'fqdn' => 'www.example.net',
            'redirects_to' => 'example.net',
            'environment_id' => Environment::factory()->create()->id,
        ]);

        $this->assertSame(3, app(DefaultChecks::class)->createFor($hostname));

        $http = $hostname->checks()->where('type', CheckType::Http)->sole();
        $this->assertSame('Doorverwijzing www.example.net', $http->name);
        $this->assertSame('https://www.example.net/', $http->target);
        $this->assertSame('example.net', $http->expectedRedirect());
        // The production site it sends visitors to has the critical check.
        $this->assertSame(AlertLevel::Normal, $http->alert_level);
        $this->assertEqualsCanonicalizing(['http', 'tls', 'dns'], $hostname->checks()->pluck('type')->map->value->all());
    }

    public function test_the_http_check_follows_the_redirect_of_its_hostname(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'www.example.net']);
        app(DefaultChecks::class)->createFor($hostname);
        $http = $hostname->checks()->where('type', CheckType::Http)->sole();
        $http->update(['settings' => ['expected_status' => 301]]);
        $other = Check::factory()->ofType(CheckType::Http, 'https://api.example.net/')->create(['hostname_id' => $hostname->id]);
        $keyword = Check::factory()->ofType(CheckType::Keyword, 'https://www.example.net/')->create(['hostname_id' => $hostname->id, 'settings' => ['keyword' => 'Welkom']]);
        // http://www often goes to https://www first, not straight to the other name.
        $plain = Check::factory()->ofType(CheckType::Http, 'http://www.example.net/')->create(['hostname_id' => $hostname->id, 'settings' => ['expected_status' => 301]]);

        $hostname->update(['redirects_to' => 'Example.net ']);

        $this->assertSame('example.net', $hostname->fresh()->redirects_to);
        $this->assertSame(['expected_status' => 301, 'redirects_to' => 'example.net'], $http->fresh()->settings);
        $this->assertSame('Doorverwijzing www.example.net', $http->fresh()->name);
        $this->assertNull($other->fresh()->expectedRedirect());
        $this->assertSame(['keyword' => 'Welkom'], $keyword->fresh()->settings);
        $this->assertSame(['expected_status' => 301], $plain->fresh()->settings);
        $this->assertNull($hostname->checks()->where('type', CheckType::Tls)->sole()->settings);

        $hostname->update(['redirects_to' => 'example.org']);
        $this->assertSame('example.org', $http->fresh()->expectedRedirect());

        $hostname->update(['redirects_to' => null]);
        $this->assertSame(['expected_status' => 301], $http->fresh()->settings);
        $this->assertSame('HTTP www.example.net', $http->fresh()->name);
    }

    public function test_a_default_name_follows_in_another_language_than_it_was_made_in(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'www.example.com']);
        app(DefaultChecks::class)->createFor($hostname);
        $http = $hostname->checks()->where('type', CheckType::Http)->sole();
        $hostname->update(['redirects_to' => 'example.com']);
        $this->assertSame('Doorverwijzing www.example.com', $http->fresh()->name);

        app()->setLocale('en');
        $hostname->update(['redirects_to' => 'example.org']);

        $this->assertSame('Redirect www.example.com', $http->fresh()->name);
    }

    public function test_the_alert_level_and_name_follow_while_they_are_the_defaults(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'www.example.net', 'environment_id' => Environment::factory()->create()->id]);
        app(DefaultChecks::class)->createFor($hostname);
        $http = $hostname->checks()->where('type', CheckType::Http)->sole();
        $named = Check::factory()->critical()->create(['hostname_id' => $hostname->id, 'name' => 'Webshop via www']);
        $quiet = Check::factory()->create(['hostname_id' => $hostname->id]);
        // A page that must answer 200 is no redirect: set up by hand, left alone.
        $page = Check::factory()->critical()->create(['hostname_id' => $hostname->id, 'settings' => ['expected_status' => 200]]);
        $this->assertSame(AlertLevel::Critical, $http->alert_level);

        $hostname->update(['redirects_to' => 'example.net']);

        // Already monitored: the site it redirects to pages, this one no longer does.
        $this->assertSame(AlertLevel::Normal, $http->fresh()->alert_level);
        $this->assertSame('Doorverwijzing www.example.net', $http->fresh()->name);
        $this->assertSame(AlertLevel::Normal, $named->fresh()->alert_level);
        $this->assertSame('Webshop via www', $named->fresh()->name);
        $this->assertSame(['expected_status' => 200], $page->fresh()->settings);
        $this->assertSame(AlertLevel::Critical, $page->fresh()->alert_level);

        // A level chosen by hand stays when the redirect moves elsewhere.
        $quiet->update(['alert_level' => AlertLevel::Critical]);
        $hostname->update(['redirects_to' => 'example.org']);
        $this->assertSame(AlertLevel::Critical, $quiet->fresh()->alert_level);
        $this->assertSame('example.org', $quiet->fresh()->expectedRedirect());

        $hostname->update(['redirects_to' => null]);

        // The name is the real site again: an outage pages.
        $this->assertSame(AlertLevel::Critical, $http->fresh()->alert_level);
        $this->assertSame('HTTP www.example.net', $http->fresh()->name);
    }

    public function test_a_redirect_set_by_hand_on_a_check_is_kept(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'www.example.net', 'redirects_to' => 'example.net']);
        $check = Check::factory()->create(['hostname_id' => $hostname->id, 'settings' => ['redirects_to' => 'elders.test']]);

        $hostname->update(['redirects_to' => null]);

        $this->assertSame('elders.test', $check->fresh()->expectedRedirect());
    }

    public function test_a_rename_and_a_new_redirect_in_one_save_both_reach_the_check(): void
    {
        $hostname = Hostname::factory()->create(['fqdn' => 'www.example.net']);
        app(DefaultChecks::class)->createFor($hostname);

        $hostname->update(['fqdn' => 'www2.example.net', 'redirects_to' => 'example.net']);

        $http = $hostname->checks()->where('type', CheckType::Http)->sole();
        $this->assertSame('https://www2.example.net/', $http->target);
        $this->assertSame('example.net', $http->expectedRedirect());
    }

    public function test_the_hostname_form_takes_a_name_and_not_itself(): void
    {
        $this->signIn();
        $domain = Domain::factory()->create(['name' => 'example.net']);
        $parameters = ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class];

        Livewire::test(HostnamesRelationManager::class, $parameters)
            ->callAction(TestAction::make('create')->table(), ['fqdn' => 'www.example.net', 'redirects_to' => 'https://example.net/'])
            ->assertHasFormErrors(['redirects_to']);

        Livewire::test(HostnamesRelationManager::class, $parameters)
            ->callAction(TestAction::make('create')->table(), ['fqdn' => 'www.example.net', 'redirects_to' => 'WWW.example.net'])
            ->assertHasFormErrors(['redirects_to']);

        Livewire::test(HostnamesRelationManager::class, $parameters)
            ->callAction(TestAction::make('create')->table(), ['fqdn' => 'www.example.net', 'redirects_to' => 'Example.net'])
            ->assertHasNoFormErrors();

        $this->assertSame('example.net', Hostname::query()->sole()->redirects_to);
    }

    public function test_the_hostname_page_links_to_the_name_it_redirects_to(): void
    {
        $this->signIn();
        $target = Hostname::factory()->create(['fqdn' => 'example.net']);
        $hostname = Hostname::factory()->create(['domain_id' => $target->domain_id, 'fqdn' => 'www.example.net', 'redirects_to' => 'example.net']);

        Livewire::test(ViewHostname::class, ['record' => $hostname->getRouteKey()])
            ->assertOk()
            ->assertSee('Stuurt door naar')
            ->assertSee(ViewHostname::getResource()::getUrl('view', ['record' => $target]), escape: false)
            ->assertActionExists('enableMonitoring', fn ($action): bool => str_contains((string) $action->getModalDescription(), 'de noodmelding hoort bij de bewaking van example.net zelf'));
    }

    public function test_a_check_made_by_hand_can_expect_a_redirect(): void
    {
        $this->signIn();
        $hostname = Hostname::factory()->create(['fqdn' => 'www.example.net']);
        $form = [
            'type' => CheckType::Http,
            'hostname_id' => $hostname->id,
            'target' => 'https://www.example.net/',
            'settings' => ['redirects_to' => 'Example.net', 'expected_status' => 200],
        ];

        Livewire::test(CreateCheck::class)
            ->fillForm($form)
            ->call('create')
            ->assertHasFormErrors(['settings.expected_status']);

        Livewire::test(CreateCheck::class)
            ->fillForm([...$form, 'settings' => ['redirects_to' => 'Example.net', 'expected_status' => 308]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('example.net', Check::query()->sole()->expectedRedirect());

        // Switched to a keyword check, the hidden redirect no longer limits the status.
        Livewire::test(CreateCheck::class)
            ->fillForm([...$form, 'type' => CheckType::Keyword, 'settings' => ['redirects_to' => 'example.net', 'expected_status' => 200, 'keyword' => 'Welkom']])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_a_redirect_that_does_not_end_is_down_unless_expected(): void
    {
        Http::fake(['*' => Http::response('', 301, ['Location' => 'https://app.example.net/'])]);
        $check = Check::factory()->ofType(CheckType::Http, 'https://app.example.net/')->create();

        $this->assertSame('HTTP 301', app(CheckRunner::class)->runOne($check)->error);

        $check->update(['settings' => ['expected_status' => 301]]);
        $this->assertTrue(app(CheckRunner::class)->runOne($check->fresh())->ok);
    }

    public function test_an_expected_redirect_is_checked_without_following_it(): void
    {
        Http::fake([
            'http://app.example.net/*' => Http::response('', 301, ['Location' => 'https://app.example.net/']),
            'https://app.example.net/*' => Http::response('ok', 200),
        ]);
        $check = Check::factory()->ofType(CheckType::Http, 'http://app.example.net/')->create(['settings' => ['expected_status' => 301]]);

        $this->assertTrue(app(CheckRunner::class)->runOne($check)->ok);
    }
}
