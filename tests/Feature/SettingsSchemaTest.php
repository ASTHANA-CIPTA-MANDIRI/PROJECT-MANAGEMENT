<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function insertSetting(string $group, string $name): void
    {
        DB::table('settings')->insert([
            'group' => $group, 'name' => $name, 'locked' => false,
            'payload' => json_encode('value'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_settings_group_and_name_pair_is_unique(): void
    {
        $this->insertSetting('audit_dup', 'flag');

        $this->expectException(QueryException::class);

        $this->insertSetting('audit_dup', 'flag');
    }

    public function test_same_name_in_a_different_group_is_allowed(): void
    {
        $before = DB::table('settings')->count();

        $this->insertSetting('audit_a', 'title');
        $this->insertSetting('audit_b', 'title');

        $this->assertSame($before + 2, DB::table('settings')->count());
    }
}
