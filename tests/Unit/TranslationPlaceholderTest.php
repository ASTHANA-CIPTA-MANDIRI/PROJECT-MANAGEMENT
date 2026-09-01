<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Machine-translated strings tend to translate the :placeholders themselves
 * (":ticket" became ":Tugas" in id.json), which silently breaks substitution:
 * Laravel leaves the token in place and the real value never reaches the user.
 *
 * Every placeholder in a source key must survive into its translation.
 */
class TranslationPlaceholderTest extends TestCase
{
    private function langPath(string $file): string
    {
        return dirname(__DIR__, 2).'/lang/'.$file;
    }

    /**
     * @return array<int, string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-zA-Z_]+)/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Locales the project actively ships to users.
     *
     * @return array<int, array{0: string}>
     */
    public function localeProvider(): array
    {
        return [
            'indonesian' => ['id.json'],
            'english' => ['en.json'],
        ];
    }

    /**
     * @dataProvider localeProvider
     */
    public function test_every_placeholder_survives_translation(string $file): void
    {
        $path = $this->langPath($file);

        if (! file_exists($path)) {
            $this->markTestSkipped("$file is not shipped.");
        }

        $translations = json_decode(file_get_contents($path), true);
        $this->assertIsArray($translations, "$file must be valid JSON.");

        $broken = [];
        foreach ($translations as $source => $translated) {
            foreach ($this->placeholders($source) as $placeholder) {
                if (! preg_match('/:'.preg_quote($placeholder, '/').'\b/', $translated)) {
                    $broken[] = sprintf(
                        '":%s" is missing from "%s" => "%s"',
                        $placeholder,
                        $source,
                        $translated
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $broken,
            "Broken placeholders in $file:\n - ".implode("\n - ", $broken)
        );
    }

    /**
     * @dataProvider localeProvider
     */
    public function test_the_language_file_is_valid_json(string $file): void
    {
        $path = $this->langPath($file);

        if (! file_exists($path)) {
            $this->markTestSkipped("$file is not shipped.");
        }

        json_decode(file_get_contents($path), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), "$file must be valid JSON.");
    }
}
