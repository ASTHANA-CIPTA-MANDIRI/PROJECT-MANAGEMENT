<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3B (Tenant Isolation), fifth and last of 5 lookup tables — see
 * database/migrations/2026_09_11_000000_add_organization_id_to_activities_table.php
 * for the pattern this follows exactly. Unlike the other 4 models, `labels`
 * never had a seeded starter set (no is_default column, no historical
 * defaults() to copy) - a Label is purely user-created tagging, so this
 * migration only adds the isolation column, nothing seeds it per-Organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labels', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('labels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
