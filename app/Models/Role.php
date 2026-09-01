<?php

namespace App\Models;

class Role extends \Spatie\Permission\Models\Role
{
    public static function boot()
    {
        parent::boot();

        // Never delete the role that designates Super Admins: doing so would
        // strip it from every user at once and could lock the platform out.
        static::deleting(function (Role $role) {
            if ($role->isSuperAdminRole()) {
                return false;
            }
        });
    }

    /**
     * Whether this is the effective Super Admin role — the one configured in
     * Settings, or (when unset) a role literally named "Super Admin".
     */
    public function isSuperAdminRole(): bool
    {
        $configuredId = User::superAdminRoleId();

        return $configuredId !== null
            ? (string) $this->getKey() === (string) $configuredId
            : $this->name === 'Super Admin';
    }
}
