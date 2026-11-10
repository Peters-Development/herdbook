<?php

namespace App\Providers;

use App\Mcp\HiddenValues;
use App\Models\AgentToken;
use App\Models\Check;
use App\Models\Client;
use App\Models\CredentialReference;
use App\Models\Document;
use App\Models\Domain;
use App\Models\DomainPrice;
use App\Models\Environment;
use App\Models\EnvironmentServer;
use App\Models\Hostname;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Network;
use App\Models\NetworkAddress;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerDependency;
use App\Models\StatusScreen;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Checks\NetworkProbe;
use App\Services\Checks\SocketProbe;
use App\Services\Costs\RecurringCosts;
use App\Services\Health\ProjectHealth;
use App\Services\Health\ProjectIncidents;
use App\Services\RenewalAttention;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Memoise their results: scoped, so they are reset for every request and job.
        $this->app->scoped(RenewalAttention::class);
        $this->app->scoped(ProjectHealth::class);
        $this->app->scoped(ProjectIncidents::class);
        $this->app->scoped(HiddenValues::class);
        $this->app->scoped(RecurringCosts::class);

        // The checks' network calls; tests bind a fake.
        $this->app->bind(NetworkProbe::class, SocketProbe::class);

        // How Filament sanitizes the HTML of markdown (TextEntry::markdown())
        // and notifications. Filament's own config lets every element keep
        // `style`, `class` and data attributes, for its rich editor, which
        // Herdbook doesn't use: with them a document or an imported incident
        // could restyle the page, say lay a fake sign-in form over it. Safe
        // elements with their safe attributes only; images may still come over
        // https (docs/security.md#what-users-write).
        $this->app->scoped(HtmlSanitizerConfig::class, fn (): HtmlSanitizerConfig => (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowAttribute('width', allowedElements: 'img')
            ->allowAttribute('height', allowedElements: 'img')
            ->withMaxInputLength(500000));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // The default language, before a signed-in account switches the app
        // to its own (SetUserLocale overwrites app.locale).
        config(['herdbook.default_locale' => config('app.locale')]);

        // Morph types are stored as these aliases, never as class names, so a
        // renamed model does not orphan its polymorphic rows. Every model that
        // takes part in a morph relation (incl. the activity log) belongs here.
        Relation::enforceMorphMap([
            'agent_token' => AgentToken::class,
            'check' => Check::class,
            'client' => Client::class,
            'credential_reference' => CredentialReference::class,
            'document' => Document::class,
            'domain' => Domain::class,
            'domain_price' => DomainPrice::class,
            'environment' => Environment::class,
            'environment_server' => EnvironmentServer::class,
            'hostname' => Hostname::class,
            'incident' => Incident::class,
            'maintenance' => Maintenance::class,
            'network' => Network::class,
            'network_address' => NetworkAddress::class,
            'project' => Project::class,
            'provider' => Provider::class,
            'server' => Server::class,
            'server_dependency' => ServerDependency::class,
            'status_screen' => StatusScreen::class,
            'subscription' => Subscription::class,
            'user' => User::class,
        ]);

        // Immutable dates: date arithmetic (renewals) never shifts a shared
        // instance by accident.
        Date::use(CarbonImmutable::class);

        FilamentTimezone::set(config('herdbook.display_timezone'));

        DB::prohibitDestructiveCommands($this->app->isProduction());

        Password::defaults(fn (): Password => Password::min(12));

        // Attachments would land on a disk nothing serves: links only.
        MarkdownEditor::configureUsing(fn (MarkdownEditor $editor) => $editor->disableToolbarButtons(['attachFiles']));
    }
}
