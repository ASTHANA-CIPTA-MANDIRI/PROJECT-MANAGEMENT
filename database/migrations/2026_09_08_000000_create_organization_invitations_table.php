<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.4 (Organization Invitation & Membership Lifecycle) — see
 * docs/adr/0001-hybrid-multi-tenant-authorization.md.
 *
 * Deliberately its own table, not a reuse/extension of organization_users:
 * a pending invitation is not a membership (no organization_users row
 * exists until acceptance), and organization_users.role/its unique
 * (organization_id, user_id) constraint stay exactly as they were before
 * this phase.
 *
 * token_hash, not the raw token: the plain, cryptographically random token
 * is only ever mailed to the recipient — the same discipline this app
 * already applies to personal access tokens (Sanctum hashes them too), and
 * stronger than the pre-existing users.creation_token column (a raw,
 * unhashed UUID) that this phase does not imitate.
 *
 * cascadeOnDelete on organization_id: an invitation with no Organization to
 * join is meaningless and must never remain acceptable (brief's explicit
 * "Organization deletion must not leave an orphan invitation that still
 * grants access" rule) — unlike projects.organization_id's nullOnDelete,
 * there is no legacy "must keep working with no tenant" case to preserve
 * here, since invitations did not exist before Organizations did.
 *
 * created_by nullOnDelete: losing the record of *who* invited someone is
 * recoverable information loss, not a reason to cascade-delete the
 * invitation itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
