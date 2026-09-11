<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3B (Tenant Isolation), second of 5 lookup tables — see
 * database/migrations/2026_09_11_000000_add_organization_id_to_activities_table.php
 * for the pattern this follows exactly (nullable, cascadeOnDelete, backfilled
 * separately by App\Console\Commands\BackfillLookupData).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
