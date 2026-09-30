<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'trial_ends_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users', 'organization_id', 'user_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'organization_id', 'id');
    }

    /**
     * Fase 7 — every subscription period this organization has ever had,
     * active or not. isSubscribed() below is the only thing that reduces
     * this to a yes/no; nothing here mutates the relation. Scoped to this
     * organization's own `id` through the FK exactly like every other
     * hasMany on this model, so there is no code path from here that could
     * read another Organization's subscription rows.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Phase 5.4 — every invitation ever created for this organization,
     * pending or not. The Organization Settings "Invitations" list filters
     * this down with OrganizationInvitation::scopePending().
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class, 'organization_id', 'id');
    }

    /**
     * Whether this user is a member of the organization at all — the
     * single-instance twin of the membership check OrganizationContext
     * already performs, named to match Project::isAccessibleBy().
     */
    public function isAccessibleBy(User $user): bool
    {
        return $this->users()->whereKey($user->id)->exists();
    }

    /**
     * Whether this user holds a role permitted to manage the organization
     * itself (Owner/Admin, not a plain Member) — same shape as
     * Project::isManageableBy(), one config-driven list of roles instead of
     * Project's single "can_manage" role, since the ADR names three tiers
     * where two of them manage.
     */
    public function isManageableBy(User $user): bool
    {
        return $this->users()
            ->whereKey($user->id)
            ->wherePivotIn('role', config('system.organizations.affectations.roles.can_manage'))
            ->exists();
    }

    /**
     * This organization's role for the given user, or null when they are not
     * a member at all — the single place both OrganizationPolicy's member-
     * management abilities and this class's own helpers below read a
     * member's role from, so a cross-organization target (someone who
     * simply isn't in this organization) is unambiguous: null, never a
     * stale/wrong role borrowed from elsewhere.
     */
    public function roleOf(User $user): ?string
    {
        return $this->users()->whereKey($user->id)->first()?->pivot->role;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->roleOf($user) === 'owner';
    }

    /**
     * How many Owners this organization currently has. The sole Owner can
     * never be demoted or removed (Phase 5's Owner protection rule) — this
     * is the single count both OrganizationPolicy::updateMemberRole() and
     * ::removeMember() check before allowing either.
     */
    public function ownerCount(): int
    {
        return $this->users()->wherePivot('role', 'owner')->count();
    }

    /**
     * Whether this organization currently has an active paid subscription —
     * the single yes/no App\Support\TrialGate reads alongside
     * trial_ends_at, and the only thing Fase 8-9 (payment/billing) will
     * ever need to make true, by writing a Subscription row from a
     * verified payment event:
     *
     *   Payment Gateway -> Verified Payment Event -> Subscription state -> isSubscribed()
     *
     * never Payment Gateway -> isSubscribed() directly, and never a role
     * mutation — subscription state and authorization (Spatie roles/
     * permissions, Policies) stay fully independent, same as every other
     * axis documented on this class.
     *
     * Read-only: one `exists()` query against this organization's own
     * subscriptions() relation (already scoped to this organization's id,
     * so this can never read another Organization's rows), no writes, no
     * gateway/HTTP call, safe to call repeatedly and on every request.
     * Deliberately does not consider trial_ends_at at all — that is
     * TrialGate's job, not this method's; this only ever answers "is there
     * a currently-active *paid* subscription".
     */
    public function isSubscribed(): bool
    {
        return $this->subscriptions()->active()->exists();
    }
}
