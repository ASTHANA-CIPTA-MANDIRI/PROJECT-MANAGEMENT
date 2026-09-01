<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket's status can change without a logged-in actor (queue jobs, artisan
 * commands, seeders, scheduled tasks). Allow the recorded activity to have no
 * user in those cases instead of crashing on a NOT NULL constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    /**
     * Not safely reversible once this migration has been up for a while:
     * TicketObserver::updating() legitimately writes user_id = null for
     * changes made via console/queue, so re-adding NOT NULL will fail
     * (or silently corrupt data) on any DB that has real rows like that.
     * Kept only for local rollback of a fresh, still-empty table.
     */
    public function down(): void
    {
        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
