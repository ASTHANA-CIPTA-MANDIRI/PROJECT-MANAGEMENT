<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 of Support Full Access (Architecture Design Phase 2, "Decision
 * 11") — the Owner-approval object Full Access requires before any session
 * at that level can exist. Deliberately its own table, not columns bolted
 * onto organization_support_sessions: a grant has a lifecycle
 * (REQUESTED -> APPROVED -> CONSUMED, or EXPIRED/REVOKED) that starts
 * before any session exists and can end (expire/be revoked) without a
 * session ever being created from it — organization_support_sessions has
 * no representation for that "not started yet" state at all.
 *
 * Every state is derived from these timestamp columns, never a stored
 * enum, mirroring OrganizationSupportSession::isActive()/scopeActive():
 *   REQUESTED = approved_at/consumed_at/revoked_at all null AND request_expires_at in the future
 *   APPROVED  = approved_at set, consumed_at/revoked_at null AND grant_expires_at in the future
 *   CONSUMED  = consumed_at set (terminal)
 *   REVOKED   = revoked_at set (terminal, only reachable before consumed_at is set)
 *   EXPIRED   = none of the above and the relevant expiry has passed
 *
 * Two separate expiry columns, not one: request_expires_at bounds how long
 * an Owner has to respond, grant_expires_at (only ever set once approved)
 * bounds how long the Super Admin then has to actually start the session —
 * an approved grant left unused is a standing risk and must not linger
 * indefinitely. See App\Support\SupportSessionContext for the state
 * transitions themselves.
 *
 * No unique constraint enforcing "one active grant per organization" at
 * the database level — that invariant is enforced in
 * SupportSessionContext::requestFullAccess() via a row lock inside a
 * transaction instead. See Phase 3's own report for why (SQLite, this
 * app's test driver, does not honor a locking clause, and a portable
 * partial/conditional unique index across the MySQL/SQLite split this app
 * already has was judged out of scope for this pass).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_support_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Audit-record semantics, same reasoning as
            // organization_support_sessions.super_admin_id and
            // organization_support_actions.actor_user_id: the fact that
            // organization X had a Full Access grant requested/approved/
            // revoked by specific people must outlive any of those
            // accounts being deleted.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason', 500);

            $table->timestamp('requested_at');
            $table->timestamp('request_expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('grant_expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // Supports the "one active grant per organization" lookup in
            // requestFullAccess() — every query that checks for an active
            // grant filters by organization_id first, then consumed_at/
            // revoked_at. Named explicitly: the auto-generated name
            // ("organization_support_access_grants_organization_id_consumed_at_revoked_at_index",
            // 80 characters) exceeds MySQL's 64-character identifier limit.
            $table->index(
                ['organization_id', 'consumed_at', 'revoked_at'],
                'osag_org_consumed_revoked_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_support_access_grants');
    }
};
