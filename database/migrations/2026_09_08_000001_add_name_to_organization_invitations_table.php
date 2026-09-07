<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.4.2 (Unified Organization Member Onboarding — Full Name).
 *
 * Nullable, not backfilled: an invitation created before this phase never
 * had a name to record, and there is nothing safe to invent for it. A null
 * name here means AcceptOrganizationInvitation leaves the accepting user's
 * own self-chosen registration name untouched (see that class) instead of
 * ever writing a blank/placeholder value over it — the same
 * nullable-for-backward-compatibility approach already used for
 * projects.organization_id (2026_09_02_000003_add_organization_id_to_projects_table.php).
 *
 * No length specified, matching users.name (2014_10_12_000000_create_users_table.php:18,
 * a bare string() column, i.e. the same 255-character default) — this
 * value is meant to become that column's value, so it must never be
 * capable of holding something users.name itself could not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->string('name')->nullable()->after('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
