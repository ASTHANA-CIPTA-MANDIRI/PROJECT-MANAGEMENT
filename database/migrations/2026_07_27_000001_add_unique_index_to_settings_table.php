<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('settings', function (Blueprint $table) {
            $table->dropIndex(['group']);
            $table->unique(['group', 'name']);
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
