<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of Support Action (see App\Support\SupportSessionContext) — the
 * only schema change this phase needs. A plain string column with a
 * default, not a DB enum, mirroring organization_users.role and
 * project_users.role exactly (this codebase's established pattern for a
 * small fixed set of values validated at the application layer, not the
 * database's) — see App\Models\OrganizationSupportSession::LEVELS.
 *
 * Not nullable, with a default: every already-existing session row (there
 * may be none yet given this table itself is only one day old, but the
 * discipline matters regardless) becomes 'read_only' the moment this
 * migration runs — the same behavior every session already had before this
 * column existed, so no existing session's capability silently changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_support_sessions', function (Blueprint $table) {
            $table->string('level')->default('read_only')->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('organization_support_sessions', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
