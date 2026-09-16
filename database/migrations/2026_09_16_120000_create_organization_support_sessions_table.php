<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin "view as Organization" support access — a temporary, audited,
 * read-only session, deliberately a separate side-channel from
 * App\Support\OrganizationContext (see docs/adr/0001-hybrid-multi-tenant-authorization.md,
 * Scenario D addendum). Never written to or read by OrganizationContext,
 * Project::accessibleBy(), or any Policy — only App\Support\SupportSessionContext
 * and the Super-Admin-gated pages that use it touch this table.
 *
 * organization_id cascadeOnDelete: a support-session record referring to an
 * organization that no longer exists is meaningless — unlike an invitation,
 * there is no "must still work with no tenant" legacy case to preserve here.
 *
 * super_admin_id nullOnDelete (not cascade): this is an audit record. If the
 * Super Admin's own account is later deleted, the fact that organization X
 * was accessed for reason Y at time Z must still be provable — losing "who"
 * is acceptable, losing the record itself is not (mirrors
 * organization_invitations.created_by).
 *
 * No status enum column: active/expired/ended is derived from
 * ended_at/expires_at (active = ended_at IS NULL AND expires_at > now()),
 * the same derived-state pattern organization_invitations already uses
 * instead of a stored flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('super_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['super_admin_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_support_sessions');
    }
};
