<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 of Support Full Access — links a full_access session back to the
 * grant that authorized it. Nullable: every existing read_only/
 * support_action session (and any future one at those levels) never has a
 * grant at all, so this column stays null for them, unchanged — see
 * App\Support\SupportSessionContext::start(), which never accepts
 * LEVEL_FULL_ACCESS regardless of input, so only
 * SupportSessionContext::consumeFullAccessGrant() ever writes a non-null
 * value here.
 *
 * nullOnDelete, not cascade: mirrors this same table's own super_admin_id
 * — a session row is itself an audit record (Support Center's whole
 * point), so it must outlive the grant row it references being removed
 * for any reason, rather than disappearing along with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_support_sessions', function (Blueprint $table) {
            $table->foreignId('access_grant_id')
                ->nullable()
                ->after('level')
                ->constrained('organization_support_access_grants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_support_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_grant_id');
        });
    }
};
