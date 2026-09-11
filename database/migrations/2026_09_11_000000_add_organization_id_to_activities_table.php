<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3B (Tenant Isolation, first of 5 lookup tables) — see
 * docs/adr/0001-hybrid-multi-tenant-authorization.md's Migration Philosophy.
 *
 * Nullable on purpose, same discipline as every other Organization column
 * added so far: existing `activities` rows (seeded once, globally, before
 * this phase) keep organization_id = NULL until a dedicated backfill
 * command assigns them to the "Default Organization" — nothing that
 * already works stops working the moment this migration runs. NULL stays
 * a permanently-legal value afterwards too (see App\Models\Concerns\
 * BelongsToOrganization) for the same reason Project::organization_id
 * does: a value created outside any authenticated Organization context
 * (console/seeder/tinker) has no Organization to stamp.
 *
 * cascadeOnDelete, unlike projects.organization_id's nullOnDelete: an
 * Activity has no independent existence worth preserving once its
 * Organization is gone — it is pure reference data the Organization owns,
 * not primary data like a Project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
