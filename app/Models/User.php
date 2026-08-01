<?php

namespace App\Models;

use Devaslanphp\FilamentAvatar\Core\HasAvatarUrl;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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

        return $roleId !== null
            ? $this->roles()->whereKey($roleId)->exists()
            : $this->hasRole('Super Admin');
    }

    /**
     * The configured Super Admin role id, or null when unset/unavailable.
     */
    public static function superAdminRoleId(): ?string
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

    public function sendEmailVerificationNotification()
    {
        $this->notify(new \App\Notifications\CustomVerifyEmail);
    }
}
