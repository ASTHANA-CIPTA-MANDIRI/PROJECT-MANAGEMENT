<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A setting is uniquely identified by its (group, name) pair. Enforce that at
 * the database level so duplicates can't be inserted silently and Spatie's
 * upserts stay correct across drivers. The composite index also covers the
 * group-prefix lookups the old single-column index handled.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->removeDuplicateSettings();

        // The old single-column index may already be gone from a partially
        // applied run (MySQL auto-commits DDL), so drop it defensively.
        try {
            Schema::table('settings', fn (Blueprint $table) => $table->dropIndex(['group']));
        } catch (\Throwable $e) {
            // Index already removed.
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->unique(['group', 'name']);
        });
    }

    /**
     * Drop any pre-existing duplicate (group, name) rows before enforcing
     * uniqueness — keeping the lowest id — so the index can be added on a
     * database that already accumulated duplicates. Portable across drivers.
     * Each removed row is logged (full column set) so a lost payload can be
     * recovered from the log if it turns out to matter.
     */
    private function removeDuplicateSettings(): void
    {
        $seen = [];

        DB::table('settings')->orderBy('id')->get()
            ->each(function ($row) use (&$seen) {
                $key = $row->group.'|'.$row->name;
                if (isset($seen[$key])) {
                    Log::warning('Migrasi unique-index settings: menghapus baris duplikat (group, name)', [
                        'kept_id' => $seen[$key],
                        'deleted_row' => (array) $row,
                    ]);
                    DB::table('settings')->where('id', $row->id)->delete();
                } else {
                    $seen[$key] = $row->id;
                }
            });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['group', 'name']);
            $table->index('group');
        });
    }
};
