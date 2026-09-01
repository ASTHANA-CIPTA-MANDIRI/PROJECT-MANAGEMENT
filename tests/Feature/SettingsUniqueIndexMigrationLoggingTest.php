<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SettingsUniqueIndexMigrationLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_settings_removal_is_logged_before_delete(): void
    {
        // Simulate a DB that still has the pre-migration duplicates by
        // temporarily dropping the unique index this migration itself adds.
        Schema::table('settings', fn (Blueprint $table) => $table->dropUnique(['group', 'name']));

        DB::table('settings')->insert([
            ['group' => 'audit_dup2', 'name' => 'flag', 'locked' => false, 'payload' => json_encode('old'), 'created_at' => now(), 'updated_at' => now()],
            ['group' => 'audit_dup2', 'name' => 'flag', 'locked' => false, 'payload' => json_encode('new'), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $keptId = DB::table('settings')->where('group', 'audit_dup2')->orderBy('id')->value('id');

        Log::spy();

        $migration = require database_path('migrations/2026_07_27_000001_add_unique_index_to_settings_table.php');
        $migration->up();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($keptId) {
                return str_contains($message, 'menghapus baris duplikat')
                    && $context['kept_id'] === $keptId
                    && $context['deleted_row']['group'] === 'audit_dup2';
            });

        $this->assertSame(1, DB::table('settings')->where('group', 'audit_dup2')->count());
        $this->assertSame($keptId, DB::table('settings')->where('group', 'audit_dup2')->value('id'));
    }
}
