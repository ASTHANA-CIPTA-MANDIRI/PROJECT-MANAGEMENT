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
}
