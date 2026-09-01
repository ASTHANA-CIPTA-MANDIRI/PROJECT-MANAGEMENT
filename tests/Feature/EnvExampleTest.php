<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A fresh deployment is a copy of .env.example, so the file's defaults and
 * comments are the security posture most installs actually ship with.
 */
class EnvExampleTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function lines(): array
    {
        return file(base_path('.env.example'), FILE_IGNORE_NEW_LINES);
    }

    /**
     * The comment block (if any) directly above the given "KEY=" line, plus
     * the line itself, lower-cased.
     */
    private function commentedContextFor(string $key): string
    {
        $lines = $this->lines();
        $index = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, $key.'=')) {
                $index = $i;
                break;
            }
        }
        $this->assertNotNull($index, "$key is not defined in .env.example");

        $start = $index;
        while ($start > 0 && str_starts_with(ltrim($lines[$start - 1]), '#')) {
            $start--;
        }

        return strtolower(implode("\n", array_slice($lines, $start, $index - $start + 1)));
    }

    public function test_it_documents_session_secure_cookie_for_production(): void
    {
        $this->assertStringContainsString('production', $this->commentedContextFor('SESSION_SECURE_COOKIE'));
    }

    public function test_it_warns_against_app_debug_true_in_production(): void
    {
        $this->assertStringContainsString('production', $this->commentedContextFor('APP_DEBUG'));
    }
}
