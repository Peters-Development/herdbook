<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Strings are English in the code (`__('…')`) and translated in
 * lang/<locale>.json. A string without a translation shows up in English in
 * the middle of a Dutch page: these tests catch that before a user does.
 */
class TranslationsTest extends TestCase
{
    private const string CALL = '/(?:__|trans|trans_choice|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")/';

    public function test_every_string_in_the_code_has_a_dutch_translation(): void
    {
        $missing = array_values(array_diff($this->keysInCode(), array_keys($this->translations('nl'))));

        $this->assertSame([], $missing, 'Add these to lang/nl.json.');
    }

    public function test_every_translation_is_still_used(): void
    {
        $source = $this->source();
        $unused = array_values(array_filter(
            array_keys($this->translations('nl')),
            fn (string $key): bool => ! str_contains($source, var_export($key, true)) && ! str_contains($source, json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ));

        $this->assertSame([], $unused, 'Remove these from lang/nl.json, or use them.');
    }

    public function test_a_translation_keeps_the_placeholders_of_its_key(): void
    {
        foreach ($this->translations('nl') as $key => $value) {
            $this->assertSame($this->placeholders($key), $this->placeholders($value), "Placeholders differ for: {$key}");
            // A plural stays a plural; how many forms a language has is its own business.
            $this->assertSame(str_contains($key, '|'), str_contains($value, '|'), "Plural forms differ for: {$key}");
        }
    }

    public function test_the_gaps_in_filaments_dutch_are_filled(): void
    {
        app()->setLocale('nl');

        // lang/vendor/filament*/nl: merged over Filament's own translation.
        $this->assertSame('Naar de inhoud', __('filament-panels::layout.skip_to_content.label'));
        $this->assertSame('Kies een optie.', __('filament-forms::components.select.required_description'));
        $this->assertSame('Teken 1 van 6', __('filament::components/input/one-time-code.aria_label', ['position' => 1, 'count' => 6]));
        // And Filament's own Dutch is still there.
        $this->assertSame('Wijzigingen opslaan', __('filament-panels::resources/pages/edit-record.form.actions.save.label'));
    }

    public function test_every_language_has_the_same_formats(): void
    {
        $english = require lang_path('en/formats.php');

        foreach (array_keys(config('herdbook.locales')) as $locale) {
            $formats = require lang_path("{$locale}/formats.php");

            $this->assertSame(array_keys($english), array_keys($formats), "lang/{$locale}/formats.php");
        }
    }

    public function test_every_language_but_english_has_a_translation_file(): void
    {
        foreach (array_keys(config('herdbook.locales')) as $locale) {
            if ($locale !== 'en') {
                $this->assertNotEmpty($this->translations($locale), "lang/{$locale}.json");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function keysInCode(): array
    {
        preg_match_all(self::CALL, $this->source(), $matches, PREG_SET_ORDER);

        // Group 1: a single-quoted key, group 2: a double-quoted one.
        $keys = array_map(fn (array $match): string => stripslashes(($match[2] ?? '') !== '' ? $match[2] : $match[1]), $matches);

        // Laravel's and Filament's own keys ("validation.required",
        // "filament-panels::…") and the formats in lang/<locale>/formats.php.
        $keys = array_filter($keys, fn (string $key): bool => $key !== ''
            && ! str_contains($key, '::')
            && preg_match('/^[a-z_]+\.[a-z_.]+$/', $key) !== 1);

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    private function source(): string
    {
        static $source = null;

        if ($source === null) {
            $finder = Finder::create()->files()->in([app_path(), resource_path(), base_path('routes'), config_path()])->name(['*.php', '*.js']);
            $source = implode("\n", array_map(fn ($file): string => $file->getContents(), iterator_to_array($finder, false)));
        }

        return $source;
    }

    /**
     * @return array<string, string>
     */
    private function translations(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/i', $text, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
