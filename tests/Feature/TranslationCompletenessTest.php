<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The application ships with `locale` and `fallback_locale` both set to `id`,
 * so a string that is missing from lang/id.json does not fail loudly: Laravel
 * simply renders the English source key. The result is a half-translated UI,
 * which is exactly how the label, mention, due-date and analytics features
 * shipped. This test walks every `__()` / `@lang()` / `trans()` literal in the
 * code base and requires a matching entry, so the gap is caught at build time
 * instead of by a user staring at an English label.
 *
 * Two families of keys are skipped on purpose:
 *  - namespaced keys (`filament::login.heading`) resolve from the package's
 *    own lang files, which already ship an `id` locale;
 *  - dotted keys (`validation.required`) resolve from lang/id/*.php.
 */
class TranslationCompletenessTest extends TestCase
{
    /**
     * Directories scanned for translation calls.
     *
     * @var array<int, string>
     */
    private const SCANNED = ['app', 'resources', 'routes', 'config', 'database'];

    /**
     * Vendor views are published copies of package templates; the strings they
     * use belong to the package's own translation files.
     */
    private const SKIPPED = 'resources/views/vendor/';

    public function test_lang_id_json_is_valid_json(): void
    {
        $translations = json_decode(file_get_contents(base_path('lang/id.json')), true);

        $this->assertIsArray($translations, 'lang/id.json is not valid JSON.');
        $this->assertNotEmpty($translations);
    }

    public function test_every_translatable_string_has_an_indonesian_translation(): void
    {
        $translations = json_decode(file_get_contents(base_path('lang/id.json')), true);

        $missing = [];

        foreach ($this->translatableStrings() as $string => $files) {
            if (! array_key_exists($string, $translations)) {
                $missing[] = $string.' ('.implode(', ', $files).')';
            }
        }

        $this->assertSame([], $missing, "Missing from lang/id.json:\n- ".implode("\n- ", $missing));
    }

    /**
     * Every literal passed to a translation helper, mapped to the files using it.
     *
     * @return array<string, array<int, string>>
     */
    private function translatableStrings(): array
    {
        $strings = [];

        foreach ($this->phpFiles() as $file) {
            $contents = file_get_contents($file);

            if (! preg_match_all('/(?:__|trans|@lang)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $contents, $matches)) {
                continue;
            }

            foreach ($matches[1] as $literal) {
                $string = str_replace(["\\'", '\\\\'], ["'", '\\'], $literal);

                if ($string === '' || $this->resolvesElsewhere($string)) {
                    continue;
                }

                $relative = str_replace(base_path().'/', '', $file);

                $strings[$string][$relative] = $relative;
            }
        }

        return array_map('array_values', $strings);
    }

    /**
     * Namespaced package keys and dotted lang/id/*.php keys are not json keys.
     */
    private function resolvesElsewhere(string $string): bool
    {
        return str_contains($string, '::')
            || preg_match('/^[a-z0-9_-]+\.[a-zA-Z0-9_.-]+$/', $string) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (self::SCANNED as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory))
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if (str_contains($file->getPathname(), self::SKIPPED)) {
                    continue;
                }

                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
