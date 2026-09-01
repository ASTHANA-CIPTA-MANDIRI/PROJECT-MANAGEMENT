<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class AddSuperAdminRoleToGeneralSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.super_admin_role');
    }
}
