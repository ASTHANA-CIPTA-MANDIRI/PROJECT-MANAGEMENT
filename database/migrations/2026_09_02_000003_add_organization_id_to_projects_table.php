<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Organization Foundation) — see
 * docs/adr/0001-hybrid-multi-tenant-authorization.md.
 *
 * Nullable on purpose (Migration Philosophy, step 2 of the ADR): existing
 * installations must keep working the instant this migration runs, before
 * organizations:backfill has assigned every project to an Organization.
 * NOT NULL is only enforced in a later phase, after backfill + validation.
 *
 * nullOnDelete, not cascadeOnDelete: deleting an Organization must never
 * cascade-delete Projects (and everything under them). Losing the tenancy
 * label on a project is recoverable; losing the project is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('owner_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
