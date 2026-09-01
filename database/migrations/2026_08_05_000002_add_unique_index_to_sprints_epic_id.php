<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // Sprint <-> Epic is meant to be 1:1 (SprintObserver creates one epic
        // per sprint, and Epic::sprint() uses hasOne). Without a unique
        // constraint, two sprints could point at the same epic_id and one
        // would silently be hidden behind hasOne's implicit first().
        Schema::table('sprints', function (Blueprint $table) {
            $table->unique('epic_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sprints', function (Blueprint $table) {
            $table->dropUnique(['epic_id']);
        });
    }
};
