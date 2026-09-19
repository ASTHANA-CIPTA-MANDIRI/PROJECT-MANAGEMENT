<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of Support Action (see App\Support\SupportSessionContext and
 * App\Models\OrganizationSupportSession) — the audit trail foundation, built
 * and tested before any write action exists so the first real action
 * (Phase 4) is required to use it from day one rather than having one
 * bolted on afterward.
 *
 * One row per single field changed on one resource during one Support
 * Session — the same granularity App\Models\TicketActivity already uses
 * for ticket status changes (old_status_id/new_status_id, not a JSON
 * snapshot), deliberately not a generic audit-log framework: this app has
 * no audit-log package (confirmed via composer.json) and no existing
 * generic mechanism to extend — App\Models\Activity is an unrelated
 * time-tracking category lookup (see ticket_hours.activity_id), not an
 * event feed.
 *
 * target_type + target_id is a plain pair of columns for later lookup, not
 * an Eloquent morphTo() relation — nothing reads them yet (no action exists
 * until Phase 4), and a real polymorphic relationship can be added exactly
 * when the first consumer needs it instead of being speculative now.
 * target_id deliberately carries no foreign key constraint: it points at
 * whichever table target_type names (Ticket today, possibly Sprint/Project
 * later), which a single FK cannot express.
 *
 * Immutable by convention (like OrganizationSupportSession/TicketActivity
 * before it): no updated_at, and nothing in Support Center ever
 * edits/deletes a row here — enforced by simply never building that UI,
 * the same discipline this table's sibling rows already rely on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_support_actions', function (Blueprint $table) {
            $table->id();

            // Cascades with its session: an action record whose Support
            // Session no longer exists is meaningless — the session itself
            // only ever disappears by cascading from its own
            // organization_id (see create_organization_support_sessions_table),
            // never on its own, so this never fires independently of the
            // organization being removed entirely.
            $table->foreignId('support_session_id')->constrained('organization_support_sessions')->cascadeOnDelete();

            // nullOnDelete, not cascade — mirrors
            // organization_support_sessions.super_admin_id exactly and for
            // the same reason: this is an audit record, so "who did this"
            // may be lost if that account is later deleted, but the fact
            // that organization X had field Y changed from A to B must
            // still be provable.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Denormalized from support_session_id (not derivable without
            // a join) so "every Support Action taken against Organization
            // X" is a direct indexed query. Cascades for the same reason
            // Activity.organization_id does: this row has no independent
            // existence worth preserving once its Organization is gone —
            // and support_session_id's own cascade would remove it anyway
            // once the session cascades from the same Organization delete,
            // so this FK never fires out of step with that one.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // e.g. "ticket.change_status" — a plain string validated by the
            // application (Phase 4+), the same convention
            // organization_users.role/project_users.role already use
            // instead of a DB enum.
            $table->string('action');

            // e.g. "App\Models\Ticket" / the ticket's id — plain columns,
            // not a morph relation (see class docblock above).
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');

            // One row = one field changed. Nullable: a future action kind
            // that isn't a single-field change (none exist yet) is not
            // forced to fabricate one.
            $table->string('field')->nullable();
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_support_actions');
    }
};
