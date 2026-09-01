<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageGeneralSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_general_settings_page_renders_with_the_super_admin_summary(): void
    {
        Permission::firstOrCreate(['name' => 'Manage general settings']);
        $role = Role::create(['name' => 'Admin']);
        $role->syncPermissions(['Manage general settings']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user->fresh());

        // Exercises the whole form schema, incl. the reactive Super Admin
        // summary placeholder.
        Livewire::test(ManageGeneralSettings::class)->assertSuccessful();
    }
}
