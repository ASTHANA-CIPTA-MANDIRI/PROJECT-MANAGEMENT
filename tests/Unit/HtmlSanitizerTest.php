<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_it_strips_script_tags(): void
    {
        $clean = HtmlSanitizer::clean('Hello <script>alert(document.cookie)</script> world');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(', $clean);
        $this->assertStringContainsString('Hello', $clean);
        $this->assertStringContainsString('world', $clean);
    }

    public function test_it_strips_inline_event_handlers(): void
    {
        $clean = HtmlSanitizer::clean('<img src="x" onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $clean);
    }

    public function test_it_strips_javascript_scheme_links(): void
    {
        $clean = HtmlSanitizer::clean('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('click', $clean);
    }

    public function test_it_removes_iframes(): void
    {
        $clean = HtmlSanitizer::clean('<iframe src="https://evil.example"></iframe>');

        $this->assertStringNotContainsString('<iframe', $clean);
    }

    public function test_it_keeps_safe_formatting(): void
    {
        $clean = HtmlSanitizer::clean('<p><strong>Bold</strong> and <em>italic</em> and <a href="https://example.com">link</a></p>');

        $this->assertStringContainsString('<strong>Bold</strong>', $clean);
        $this->assertStringContainsString('<em>italic</em>', $clean);
        $this->assertStringContainsString('href="https://example.com"', $clean);
    }

    public function test_it_passes_null_through(): void
    {
        $this->assertNull(HtmlSanitizer::clean(null));
    }

    public function test_it_is_idempotent(): void
    {
        $once = HtmlSanitizer::clean('<p>Safe <strong>text</strong></p>');
        $twice = HtmlSanitizer::clean($once);

        $this->assertSame($once, $twice);
    }

    public function test_html_purifier_is_installed(): void
    {
        $this->assertTrue(
            class_exists(\HTMLPurifier::class),
            'HTMLPurifier is missing; every ticket/comment write would fatal.'
        );
    }

    /**
     * HTMLPurifier used to be pulled in only as a transitive dependency of
     * phpspreadsheet (via maatwebsite/excel). Dropping the Excel export — or a
     * major bump that stops requiring phpspreadsheet — would silently remove
     * the only sanitizer this app has, so it must stay a direct requirement.
     */
    public function test_html_purifier_is_a_direct_composer_requirement(): void
    {
        // Plain PHPUnit TestCase: no application container, so no base_path().
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey(
            'ezyang/htmlpurifier',
            $composer['require'] ?? [],
            'ezyang/htmlpurifier must be declared in composer.json "require", not inherited transitively.'
        );
    }
}
