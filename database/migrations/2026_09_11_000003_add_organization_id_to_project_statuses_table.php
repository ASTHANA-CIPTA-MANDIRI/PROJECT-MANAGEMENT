<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3B (Tenant Isolation), fourth of 5 lookup tables — see
 * database/migrations/2026_09_11_000000_add_organization_id_to_activities_table.php
 * for the pattern this follows exactly. projects.status_id stays NOT NULL
 * and untouched — this migration only adds organization_id to
 * project_statuses itself, the table Project::status_id references, not
 * the reference on Project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_statuses', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
