<?php

namespace App\Models;

use Devaslanphp\FilamentAvatar\Core\HasAvatarUrl;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use JeffGreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use ProtoneMedia\LaravelVerifyNewEmail\MustVerifyNewEmail;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use HasApiTokens,
        HasAvatarUrl,
        HasFactory,
        HasRoles,
        MustVerifyNewEmail,
        Notifiable,
        SoftDeletes,
        TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'creation_token',
        'type',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Users the given user may see: themselves, plus everyone who owns or
     * belongs to a project they can access. Keeps people from other tenants
     * out of dashboard aggregations.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereKey($user->id)
            ->orWhereHas('projectsOwning', fn (Builder $query) => $query->accessibleBy($user))
            ->orWhereHas('projectsAffected', fn (Builder $query) => $query->accessibleBy($user)));
    }

    public function projectsOwning(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id', 'id');
    }

    public function projectsAffected(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_users', 'user_id', 'project_id')->withPivot(['role']);
    }

    public function favoriteProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_favorites', 'user_id', 'project_id');
    }

    public function ticketsOwned(): HasMany
    {
        return $this->hasMany(Ticket::class, 'owner_id', 'id');
    }

    public function ticketsResponsible(): HasMany
    {
        return $this->hasMany(Ticket::class, 'responsible_id', 'id');
    }

    public function socials(): HasMany
    {
        return $this->hasMany(SocialiteUser::class, 'user_id', 'id');
    }

    public function hours(): HasMany
    {
        return $this->hasMany(TicketHour::class, 'user_id', 'id');
    }

    public function totalLoggedInHours(): Attribute
    {
        return new Attribute(
            get: function () {
                return $this->hours->sum('value');
            }
        );
    }

    public function canAccessFilament(): bool
    {
        // Only users with at least one assigned role may access the panel.
        // Roles carry all granular permissions, so a user without any role
        // has no permissions and must not be allowed into the admin panel.
        return $this->roles()->exists();
    }

    /**
     * The main administrator. Which role counts as "Super Admin" is chosen in
     * Settings; when unset we fall back to a role literally named "Super Admin".
     */
    public function isSuperAdmin(): bool
    {
        $roleId = static::superAdminRoleId();

        // Reuse an already eager-loaded roles relation instead of firing a
        // fresh exists() query per user (e.g. per row of the users table).
        if ($this->relationLoaded('roles')) {
            return $roleId !== null
                ? $this->roles->contains('id', $roleId)
                : $this->roles->contains('name', 'Super Admin');
        }

        return $roleId !== null
            ? $this->roles()->whereKey($roleId)->exists()
            : $this->hasRole('Super Admin');
    }

    /**
     * The configured Super Admin role id, or null when unset/unavailable.
     *
     * Cached for the lifetime of the container (i.e. per request/per test):
     * the value can't change mid-request, but isSuperAdmin() may be called
     * once per row of a users table, and without this the dangling-reference
     * check below would run one query per row.
     */
    public static function superAdminRoleId(): ?string
    {
        $cacheKey = static::class.'@superAdminRoleId';
        if (app()->bound($cacheKey)) {
            return app($cacheKey)['id'];
        }

        $id = static::resolveSuperAdminRoleId();
        app()->instance($cacheKey, ['id' => $id]);

        return $id;
    }

    private static function resolveSuperAdminRoleId(): ?string
    {
        try {
            $id = app(\App\Settings\GeneralSettings::class)->super_admin_role ?: null;
        } catch (\Throwable $e) {
            // Settings not available yet (e.g. no database / faked settings).
            return null;
        }

        // Heal a dangling reference: if the configured role no longer exists,
        // treat it as unset so isSuperAdmin() falls back to a role named
        // "Super Admin" instead of silently having no Super Admins at all.
        if ($id !== null && ! Role::whereKey($id)->exists()) {
            return null;
        }

        return $id;
    }

    /**
     * Query for users holding the Super Admin role.
     */
    public static function superAdmins(): \Illuminate\Database\Eloquent\Builder
    {
        $roleId = static::superAdminRoleId();

        return static::whereHas('roles', fn ($q) => $roleId !== null
            ? $q->whereKey($roleId)
            : $q->where('name', 'Super Admin'));
    }

    /**
     * True when this user is a Super Admin and no other user holds the role —
     * removing it would leave the platform with no administrator.
     */
    public function isLastSuperAdmin(): bool
    {
        return $this->isSuperAdmin()
            && static::superAdmins()->whereKeyNot($this->getKey())->doesntExist();
    }

    /**
     * Ids of every permission this user effectively holds, through any role.
     */
    public function heldPermissionIds(): array
    {
        return $this->getAllPermissions()->pluck('id')->map('strval')->all();
    }

    /**
     * Whether this user holds every one of the given permissions. The rule
     * behind every "you cannot grant what you do not have yourself" check:
     * without it, any permission to manage roles or users would be a shortcut
     * to every privilege in the system.
     */
    public function holdsAllPermissions(array $permissionIds): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $required = array_map('strval', $permissionIds);

        return array_diff($required, $this->heldPermissionIds()) === [];
    }

    /**
     * Whether this user may hand out the given role. A non-Super-Admin may only
     * grant roles whose permissions they already hold, and may never grant the
     * Super Admin role itself — whatever permissions that role happens to carry
     * on this particular instance.
     */
    public function canGrantRole(Role $role): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($role->isSuperAdminRole()) {
            return false;
        }

        return $this->holdsAllPermissions(
            $role->permissions->pluck('id')->all()
        );
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify(new \App\Notifications\CustomVerifyEmail);
    }
}
