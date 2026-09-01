<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ticket_statuses', function (Blueprint $table) {
            $table->boolean('is_final')->default(false)->after('is_default');
        });

        // Best-effort backfill for statuses already in use on existing
        // instances, so due-date reminders stop firing for them immediately
        // after upgrade instead of waiting on a manual admin edit.
        DB::table('ticket_statuses')
            ->whereIn('name', ['Done', 'Archived'])
            ->update(['is_final' => true]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ticket_statuses', function (Blueprint $table) {
            $table->dropColumn('is_final');
        });
    }
};
