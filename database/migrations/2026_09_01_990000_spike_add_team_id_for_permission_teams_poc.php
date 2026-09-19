<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPIKE-ONLY MIGRATION - branch spike/spatie-permission-teams.
 *
 * NOT part of the jarne history. This exists purely so the Phase 1 Spatie
 * Permission Teams proof-of-concept has real `team_id` columns to query
 * against in the test database, without editing the existing, already-run
 * 2022_11_02_113007_create_permission_tables.php migration (which branches
 * on config('permission.teams') at the time it *first* ran - flipping that
 * config now would not retroactively add the columns to an already-migrated
 * database, exactly as Spatie's own package migration comment warns).
 *
 * Mirrors exactly what spatie/laravel-permission's own migration creates for
 * 'roles', 'model_has_roles' and 'model_has_permissions' when
 * config('permission.teams') is true - same column, index and primary-key
 * shape - so the POC's behavior matches what enabling teams for real would
 * produce.
 *
 * MUST NOT be merged into jarne or any other branch.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('roles', function (Blueprint $table) {
            // The original 2022 migration baked in unique(['name','guard_name'])
            // because $teams was false the day it first ran. Spatie's own
            // migration comment warns this exact case: enabling teams later
            // needs a NEW migration that also fixes this index - adding the
            // column alone is not enough, roles.name stays globally unique
            // and two orgs could never both have an "Admin" role. Reproduced
            // here, not just asserted, after Scenario A's first run proved it
            // by hitting the old constraint for real.
            $table->dropUnique(['name', 'guard_name']);
            $table->unsignedBigInteger('team_id')->nullable()->after('id');
            $table->index('team_id', 'spike_roles_team_id_index');
            $table->unique(['team_id', 'name', 'guard_name'], 'spike_roles_team_name_guard_unique');
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->after('role_id');
            $table->index('team_id', 'spike_model_has_roles_team_id_index');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->after('permission_id');
            $table->index('team_id', 'spike_model_has_permissions_team_id_index');
        });
    }

    public function down()
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('spike_roles_team_name_guard_unique');
            $table->dropIndex('spike_roles_team_id_index');
            $table->dropColumn('team_id');
            $table->unique(['name', 'guard_name']);
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropIndex('spike_model_has_roles_team_id_index');
            $table->dropColumn('team_id');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropIndex('spike_model_has_permissions_team_id_index');
            $table->dropColumn('team_id');
        });
    }
};
