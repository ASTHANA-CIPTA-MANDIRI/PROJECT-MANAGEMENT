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

    public function down(): void
    {
        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
