<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Filament's ColorPicker never validated its value server-side, so a row
 * written before the new ->regex() rule could hold anything - including a
 * value crafted to inject extra CSS declarations into the
 * style="background-color: ..." attribute every color is rendered into. This
 * migration normalizes anything that isn't a 6-digit hex string back to the
 * table's own default.
 */
class ColorNormalizationMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> table => [factory-ish table, default color] */
    public static function tableProvider(): array
    {
        return [
            'project_statuses' => ['project_statuses', '#cecece'],
            'ticket_statuses' => ['ticket_statuses', '#cecece'],
            'ticket_types' => ['ticket_types', '#cecece'],
            'ticket_priorities' => ['ticket_priorities', '#cecece'],
            'labels' => ['labels', '#3b82f6'],
        ];
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_08_19_000000_normalize_invalid_color_values.php');
        $migration->up();
    }

    private function insert(string $table, string $color): int
    {
        $now = now();

        return DB::table($table)->insertGetId(array_merge(
            ['name' => 'Row', 'color' => $color, 'created_at' => $now, 'updated_at' => $now],
            $table === 'ticket_types' ? ['icon' => 'bug'] : [],
        ));
    }

    /**
     * @dataProvider tableProvider
     */
    public function test_a_css_injection_payload_is_replaced_with_the_default(string $table, string $default): void
    {
        $id = $this->insert($table, '#fff; position:fixed; inset:0; background:url(https://evil.example/log)');

        $this->runMigration();

        $this->assertSame($default, DB::table($table)->find($id)->color);
    }

    /**
     * @dataProvider tableProvider
     */
    public function test_a_valid_hex_color_is_left_untouched(string $table): void
    {
        $id = $this->insert($table, '#1a2b3c');

        $this->runMigration();

        $this->assertSame('#1a2b3c', DB::table($table)->find($id)->color);
    }

    /**
     * @dataProvider tableProvider
     */
    public function test_a_short_hex_color_is_also_normalized(string $table, string $default): void
    {
        // The 3-digit CSS shorthand (#fff) is not what any part of this app
        // writes or expects - only the 6-digit form is treated as valid.
        $id = $this->insert($table, '#fff');

        $this->runMigration();

        $this->assertSame($default, DB::table($table)->find($id)->color);
    }

    public function test_it_is_idempotent(): void
    {
        $id = $this->insert('labels', 'javascript:alert(1)');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame('#3b82f6', DB::table('labels')->find($id)->color);
    }
}
