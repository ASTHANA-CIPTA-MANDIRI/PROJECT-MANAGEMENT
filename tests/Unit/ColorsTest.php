<?php

namespace Tests\Unit;

use App\Support\Colors;
use PHPUnit\Framework\TestCase;

class ColorsTest extends TestCase
{
    public function test_a_well_formed_hex_color_passes_through(): void
    {
        $this->assertSame('#1a2b3c', Colors::safe('#1a2b3c'));
    }

    public function test_a_hex_color_is_case_insensitive(): void
    {
        $this->assertSame('#AaBbCc', Colors::safe('#AaBbCc'));
    }

    public function test_null_falls_back_to_the_default(): void
    {
        $this->assertSame(Colors::DEFAULT, Colors::safe(null));
    }

    public function test_an_empty_string_falls_back_to_the_default(): void
    {
        $this->assertSame(Colors::DEFAULT, Colors::safe(''));
    }

    public function test_the_three_digit_css_shorthand_is_rejected(): void
    {
        $this->assertSame(Colors::DEFAULT, Colors::safe('#fff'));
    }

    public function test_a_css_injection_payload_is_replaced(): void
    {
        $payload = '#fff; position:fixed; inset:0; background:url(https://evil.example/log)';

        $this->assertSame(Colors::DEFAULT, Colors::safe($payload));
    }

    public function test_a_value_that_breaks_out_of_the_style_attribute_is_replaced(): void
    {
        $this->assertSame(Colors::DEFAULT, Colors::safe('red" onmouseover="alert(1)'));
    }

    public function test_a_named_css_color_is_rejected(): void
    {
        // Only the hex form is accepted, even though "red" is valid CSS - one
        // format, matching what every ColorPicker in the app now enforces.
        $this->assertSame(Colors::DEFAULT, Colors::safe('red'));
    }
}
