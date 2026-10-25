<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\SetUserLocale;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // The app's own assets are versioned by their content: a deploy that
        // changes one is never served from a browser cache.
        FilamentAsset::appVersion(hash('xxh128', implode('', array_map(
            fn (Asset $asset): string => hash_file('xxh128', $asset->getPath()),
            self::assets(),
        ))));
    }

    /**
     * The app's own assets, published by `filament:assets` (in development:
     * run it after changing one).
     *
     * @return list<Asset>
     */
    private static function assets(): array
    {
        return [
            Css::make('herdbook', resource_path('css/herdbook.css')),
            // The map (Pages\Map), loaded only there (x-load).
            AlpineComponent::make('herdbook-map', resource_path('js/herdbook-map.js')),
        ];
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            // Relationship repeaters and link pivots are saved before the record
            // itself: one failure must roll all of it back.
            ->databaseTransactions()
            ->brandName('Herdbook')
            ->brandLogo(fn (): View => view('filament.brand'))
            ->brandLogoHeight('2rem')
            // Favicons, app icons and the manifest: resources/views/filament/head.blade.php.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('filament.head'))
            // Self-hosted: Herdbook loads no fonts (or avatars) from a third party.
            ->font('IBM Plex Sans', url: asset('fonts/ibm-plex/ibm-plex.css'), provider: LocalFontProvider::class)
            ->monoFont('IBM Plex Mono', provider: LocalFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->assets(self::assets())
            // Dark until someone picks another theme in the user menu.
            ->defaultThemeMode(ThemeMode::Dark)
            ->login()
            ->profile(EditProfile::class, isSimple: false)
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->brandName('Herdbook')
                    ->recoverable(),
                isRequired: true,
            )
            ->colors([
                // The ear tag in the logo. Filament picks dark text on it by contrast.
                'primary' => Color::Yellow,
                'gray' => Color::Neutral,
                // Yellow is the brand; warnings (maintenance, needs a check) move
                // to orange so the two never read as the same thing.
                'warning' => Color::Orange,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // The menu groups and their order: App\Enums\NavigationGroup.
            // The project page is where you work: its relation managers
            // (environments, incidents, ...) must be editable there too.
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // The language of the account (its profile), also for Livewire requests.
                SetUserLocale::class,
            ], isPersistent: true)
            // Livewire update requests skip route middleware unless it is
            // persistent: without this, a page opened before two-factor was
            // turned off keeps working.
            ->persistentMiddleware([
                EnsureMultiFactorAuthenticationIsEnabled::class,
            ]);
    }
}
