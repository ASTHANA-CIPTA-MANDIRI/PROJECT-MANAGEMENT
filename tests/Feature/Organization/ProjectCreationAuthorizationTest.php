<?php

namespace Tests\Feature\Organization;

use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 5.3B — Project Authorization + Create Project. Covers the brief's
 * A-U security list directly against the real Filament page
 * (App\Filament\Resources\ProjectResource\Pages\CreateProject), not just
 * ProjectPolicy in isolation, plus the owner_id picker's new
 * Organization-safety rule (ProjectForm).
 */
class ProjectCreationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * "List projects" is required to open ANY ProjectResource page at all
     * (Filament core: Page::authorizeResourceAccess() -> canViewAny() ->
     * ProjectPolicy::viewAny()) - unrelated to Phase 5.3B, but necessary for
     * these tests to reach CreateProject's own authorizeAccess() at all.
     */
    private function actorWithPermission(string $role = 'owner'): User
    {
        Permission::firstOrCreate(['name' => 'Create project']);
        Permission::firstOrCreate(['name' => 'List projects']);
        $spatieRole = Role::create(['name' => 'r_'.uniqid()]);
        $spatieRole->syncPermissions(['Create project', 'List projects']);
        $user = User::factory()->create();
        $user->syncRoles([$spatieRole]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();

        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'A New Project',
            'ticket_prefix' => 'ANP',
            'status_id' => ProjectStatus::factory()->create()->id,
            'type' => 'kanban',
            'status_type' => 'default',
        ], $overrides);
    }

    // ---------------------------------------------------------------- A, B

    public function test_organization_owner_can_create_a_project(): void
    {
        $owner = $this->actorWithPermission('owner');
        $this->actingAs($owner);

        Livewire::test(CreateProject::class)
            ->fillForm($this->validPayload())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', ['name' => 'A New Project', 'owner_id' => $owner->id]);
    }

    public function test_organization_admin_can_create_a_project(): void
    {
        $admin = $this->actorWithPermission('admin');
        $this->actingAs($admin);

        Livewire::test(CreateProject::class)
            ->fillForm($this->validPayload())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', ['name' => 'A New Project']);
    }

    // ------------------------------------------------------------------- C

    public function test_organization_member_cannot_open_the_create_project_page(): void
    {
        $member = $this->actorWithPermission('member');
        $this->actingAs($member);

        Livewire::test(CreateProject::class)->assertForbidden();

        $this->assertDatabaseMissing('projects', ['owner_id' => $member->id]);
    }

    // ------------------------------------------------------------------- D

    public function test_user_without_an_organization_cannot_open_the_create_project_page(): void
    {
        Permission::firstOrCreate(['name' => 'Create project']);
        Permission::firstOrCreate(['name' => 'List projects']);
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $role->syncPermissions(['Create project', 'List projects']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user->fresh());

        Livewire::test(CreateProject::class)->assertForbidden();
    }

    // ------------------------------------------------------------------- E

    public function test_super_admin_without_an_organization_cannot_create_a_project(): void
    {
        // A real Super Admin holds every permission (PermissionsSeeder) —
        // replicated here so this test's 403 is provably caused by the
        // missing Organization, not incidentally by a missing "List
        // projects"/"Create project" permission this role would actually
        // carry in production.
        Permission::firstOrCreate(['name' => 'Create project']);
        Permission::firstOrCreate(['name' => 'List projects']);
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdminRole->syncPermissions(['Create project', 'List projects']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($superAdmin->fresh());

        Livewire::test(CreateProject::class)->assertForbidden();
    }

    // ------------------------------------------------------------------- F

    /**
     * Filament's own CreateAction on ListRecords is wired to the resource's
     * canCreate() (Filament\Pages\Concerns\HasRoutes /
     * Filament\Resources\Pages\ListRecords), which is exactly
     * ProjectPolicy::create() — the same real gate proven directly above
     * (test_organization_member_cannot_open_the_create_project_page), not a
     * separate, weaker UI-only check. The List page itself stays reachable
     * (List projects is a distinct permission from Create project), only
     * the create button/route is denied.
     */
    public function test_the_list_page_still_renders_for_a_plain_member_who_cannot_create(): void
    {
        // actorWithPermission() grants both "Create project" and "List
        // projects" — a Member still gets nothing from either, since
        // Create is denied by Organization role, not by the flat
        // permission this member does genuinely hold.
        $member = $this->actorWithPermission('member');
        $this->actingAs($member);

        Livewire::test(\App\Filament\Resources\ProjectResource\Pages\ListProjects::class)
            ->assertSuccessful();

        Livewire::test(CreateProject::class)->assertForbidden();
    }

    // ---------------------------------------------------------- Organization context

    /**
     * organization_id is never a form field at all — it is always resolved
     * server-side from OrganizationContext::current() inside
     * ProjectObserver::creating(). Proven here across a real multi-org
     * switch, through the actual Livewire page, not just the model.
     */
    public function test_create_project_always_lands_in_the_active_organization(): void
    {
        $user = $this->actorWithPermission('owner');
        $organizationB = Organization::factory()->create();
        $organizationB->users()->attach($user->id, ['role' => 'owner']);
        $organizationA = $user->organizations()->orderBy('organization_users.id')->first();
        $this->actingAs($user);

        OrganizationContext::switch($user, $organizationA->id);
        Livewire::test(CreateProject::class)
            ->fillForm($this->validPayload(['name' => 'Into A', 'ticket_prefix' => 'INA']))
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('projects', ['name' => 'Into A', 'organization_id' => $organizationA->id]);

        OrganizationContext::switch($user, $organizationB->id);
        Livewire::test(CreateProject::class)
            ->fillForm($this->validPayload(['name' => 'Into B', 'ticket_prefix' => 'INB']))
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('projects', ['name' => 'Into B', 'organization_id' => $organizationB->id]);
    }

    // ---------------------------------------------------------- Owner picker safety

    public function test_owner_picker_only_offers_members_of_the_active_organization(): void
    {
        $owner = $this->actorWithPermission('owner');
        $organization = $owner->organizations()->firstOrFail();
        $fellowMember = User::factory()->create(['name' => 'Fellow Member']);
        $organization->users()->attach($fellowMember->id, ['role' => 'member']);
        $outsider = User::factory()->create(['name' => 'Outsider Person']);
        $this->actingAs($owner);

        $component = Livewire::test(CreateProject::class);
        $field = $component->instance()->form->getFlatFields()['owner_id'];
        $options = $field->getOptions();

        $this->assertArrayHasKey($owner->id, $options);
        $this->assertArrayHasKey($fellowMember->id, $options);
        $this->assertArrayNotHasKey($outsider->id, $options);
    }

    /**
     * The options list is a UI convenience only — this proves the real gate
     * is server-side: a crafted payload naming a real user who simply does
     * not belong to the active Organization is rejected, not silently
     * accepted because the id happens to exist in `users`.
     */
    public function test_owner_id_cannot_be_set_to_a_user_outside_the_active_organization(): void
    {
        $owner = $this->actorWithPermission('owner');
        $outsider = User::factory()->create();
        $this->actingAs($owner);

        Livewire::test(CreateProject::class)
            ->fillForm($this->validPayload(['owner_id' => $outsider->id]))
            ->call('create')
            ->assertHasFormErrors(['owner_id']);

        $this->assertDatabaseMissing('projects', ['name' => 'A New Project']);
    }

    public function test_owner_id_cannot_be_set_to_a_user_from_a_different_organization(): void
    {
        $owner = $this->actorWithPermission('owner');
        $otherOrganization = Organization::factory()->create();
        $foreignUser = User::factory()->create();
        $otherOrganization->users()->attach($foreignUser->id, ['role' => 'member']);
        $this->actingAs($owner);

        Livewire::test(CreateProject::class)
            ->fillForm($this->validPayload(['owner_id' => $foreignUser->id]))
            ->call('create')
            ->assertHasFormErrors(['owner_id']);

        $this->assertDatabaseMissing('projects', ['name' => 'A New Project']);
    }

    public function test_owner_id_can_be_set_to_any_member_of_the_active_organization(): void
    {
        $owner = $this->actorWithPermission('owner');
        $organization = $owner->organizations()->firstOrFail();
        $fellowMember = User::factory()->create();
        $organization->users()->attach($fellowMember->id, ['role' => 'member']);
        $this->actingAs($owner);

        Livewire::test(CreateProject::class)
            ->fillForm($this->validPayload(['owner_id' => $fellowMember->id]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', ['name' => 'A New Project', 'owner_id' => $fellowMember->id]);
    }
}
