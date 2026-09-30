<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7 (Subscription foundation) — the internal domain object
 * App\Models\Organization::isSubscribed() reads, replacing the
 * `return false;` stub. Deliberately not a payment/billing table: no
 * gateway reference, no amount, no currency, no invoice — Fase 8-9 own
 * that, and will drive this table's rows from a verified payment event
 * rather than this table ever calling out to a gateway itself. See
 * docs/adr/0001-hybrid-multi-tenant-authorization.md ("Subscription/Plan →
 * Feature entitlement and limits (future phase, not RBAC)") and
 * App\Support\TrialGate, which this table is deliberately kept separate
 * from — trial_ends_at stays the trial clock, this table is only ever the
 * *paid* state.
 *
 * No status enum column: active/expired/cancelled is derived from
 * starts_at/ends_at/cancelled_at, the same derived-state pattern
 * organization_invitations, organization_support_sessions and
 * organization_support_access_grants already use instead of a stored flag
 * (see Subscription::isActive()/scopeActive()).
 *
 * ends_at nullable: null means no fixed end date (an unlimited/manually
 * granted subscription), the same "nullable = unlimited" convention
 * organizations.trial_ends_at already established — never treated as
 * "not started" or "invalid", always as "does not expire".
 *
 * cancelled_at nullable, separate from ends_at: a subscription can be
 * cancelled effective immediately while its paid period (ends_at) is still
 * in the future, or simply run out without ever being cancelled — these
 * are two different reasons isActive() is false and Fase 8-9 will want to
 * tell apart (e.g. "cancelled, refund the remainder" vs "simply lapsed").
 *
 * organization_id cascadeOnDelete: same reasoning as
 * organization_support_sessions.organization_id — a subscription referring
 * to an organization that no longer exists is meaningless, unlike
 * projects.organization_id (nullOnDelete) which protects data that must
 * outlive its tenancy label.
 *
 * No unique constraint enforcing "at most one active subscription per
 * organization" — Fase 7 only needs to *read* subscription state
 * (Organization::isSubscribed()), not prevent duplicates from ever being
 * created. Fase 8-9, which actually creates rows here from payment events,
 * is the right place to decide how that invariant is enforced (mirrors the
 * same deliberate omission and reasoning documented on
 * organization_support_access_grants for "one active grant per
 * organization").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // Every read this phase adds (Subscription::scopeActive(), and
            // therefore Organization::isSubscribed()) filters by
            // organization_id first, then cancelled_at — matching the
            // composite index organization_support_access_grants already
            // uses for the same "active row for this organization" shape.
            $table->index(['organization_id', 'cancelled_at'], 'subscriptions_org_cancelled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
