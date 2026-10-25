<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Support\InitialsAvatarProvider;
use App\Models\Server;
use App\Models\User;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * The look: logo, favicons, self-hosted fonts and avatars, mono for technical values, the brand colour.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_the_login_page_carries_the_logo_and_the_stylesheet(): void
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee('class="hb-brand"', false)
            ->assertSee('class="hb-brand-name">Herdbook<', false)
            ->assertSee('css/app/herdbook.css?v=', false)
            ->assertSee('fonts/ibm-plex/ibm-plex.css', false);
    }

    public function test_the_panel_starts_dark_until_someone_picks_another_theme(): void
    {
        $this->assertSame(ThemeMode::Dark, Filament::getPanel('admin')->getDefaultThemeMode());

        // Filament's own script reads the choice, and falls back to the default.
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee("localStorage.getItem('filament:theme:mode') ?? 'dark'", false);
    }

    public function test_every_local_file_the_head_points_to_exists(): void
    {
        $html = $this->get(route('filament.admin.auth.login'))->assertOk()->getContent();

        preg_match_all('/<link[^>]+rel="(?:icon|apple-touch-icon|manifest)"[^>]+href="([^"]+)"/', $html, $matches);

        $this->assertCount(4, $matches[1], 'Expected favicon.ico, favicon.svg, the apple-touch-icon and the manifest.');

        foreach ($matches[1] as $url) {
            $this->assertFileExists(public_path(ltrim(parse_url($url, PHP_URL_PATH), '/')));
        }
    }

    public function test_the_manifest_icons_exist_at_the_size_they_claim(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Herdbook', $manifest['name']);
        $this->assertNotEmpty($manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);

            [$width, $height] = getimagesize($path);
            $this->assertSame($icon['sizes'], "{$width}x{$height}", $icon['src']);
        }
    }

    public function test_every_font_file_the_stylesheet_names_exists(): void
    {
        $css = file_get_contents(public_path('fonts/ibm-plex/ibm-plex.css'));

        preg_match_all("/url\\('([^']+)'\\)/", $css, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $file) {
            $this->assertFileExists(public_path("fonts/ibm-plex/{$file}"));
        }
    }

    public function test_the_avatar_is_drawn_here_and_not_fetched_from_a_third_party(): void
    {
        $this->signIn();

        $this->get('/')->assertOk()->assertDontSee('ui-avatars.com', false);
    }

    public function test_the_avatar_shows_the_initials(): void
    {
        $avatar = (new InitialsAvatarProvider)->get(User::factory()->make(['name' => 'ada lovelace van dijk']));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $this->assertStringContainsString('>AL</text>', base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,'))));
    }

    public function test_the_avatar_skips_leading_punctuation(): void
    {
        $svg = fn (string $name): string => base64_decode(substr(
            (new InitialsAvatarProvider)->get(User::factory()->make(['name' => $name])),
            strlen('data:image/svg+xml;base64,'),
        ));

        $this->assertStringContainsString('>SA</text>', $svg('[SYSTEM] admin'));
        $this->assertStringContainsString('>S</text>', $svg('<script>'));
        $this->assertStringNotContainsString('<script', $svg('<script>'));
    }

    public function test_technical_values_are_set_in_the_mono_font(): void
    {
        $this->signIn();
        $server = Server::factory()->create(['public_ipv4' => '203.0.113.14']);

        $this->get(ServerResource::getUrl('view', ['record' => $server]))
            ->assertOk()
            ->assertSeeInOrder(['fi-font-mono', '203.0.113.14'], false);
    }

    public function test_warnings_do_not_share_the_brand_colour(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::Yellow, $colors['primary']);
        $this->assertNotSame($colors['primary'], $colors['warning']);
    }
}
