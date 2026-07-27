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
}
