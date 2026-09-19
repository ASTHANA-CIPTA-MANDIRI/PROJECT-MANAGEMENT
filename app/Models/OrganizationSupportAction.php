<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field changed on one resource during one Support Session — the audit
 * trail Phase 4's first write action (and every one after it) is required
 * to create alongside its own write, inside the same transaction. See the
 * create_organization_support_actions_table migration's own docblock for
 * why this is a plain field/old_value/new_value row (mirroring
 * TicketActivity) rather than a generic audit-log framework or a JSON
 * snapshot.
 *
 * Immutable by convention, not by database enforcement — the same
 * discipline OrganizationSupportSession/TicketActivity already rely on: no
 * write path in this app updates or deletes a row here (Support Center
 * only ever reads/creates), so none is built. UPDATED_AT is disabled
 * outright (see below) since a row that can be edited after the fact would
 * defeat the point of an audit trail.
 */
class OrganizationSupportAction extends Model
{
    /**
     * This table has no updated_at column at all — an audit row is written
     * once and never touched again, so there is nothing for Eloquent to
     * keep in sync.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'support_session_id',
        'actor_user_id',
        'organization_id',
        'action',
        'target_type',
        'target_id',
        'field',
        'old_value',
        'new_value',
    ];

    public function supportSession(): BelongsTo
    {
        return $this->belongsTo(OrganizationSupportSession::class, 'support_session_id', 'id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
