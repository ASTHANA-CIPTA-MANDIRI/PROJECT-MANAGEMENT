<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\OrganizationSupportView;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\SupportSessionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Read-only by construction (see the class docblock on OrganizationSupportView
 * for why): access requires both isSuperAdmin() AND an active
 * App\Support\SupportSessionContext session, and ending the session locks the
 * page back out immediately — mirroring PlatformOrganizationsTest's own
 * "hiding the nav entry is not the authorization" discipline.
 */
class OrganizationSupportViewTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    public function test_super_admin_with_an_active_session_sees_only_that_organizations_projects(): void
    {
        $admin = $this->superAdmin();

        $targetOrg = Organization::factory()->create(['name' => 'Target Co']);
        Project::factory()->create(['organization_id' => $targetOrg->id, 'name' => 'Target Project']);

        $otherOrg = Organization::factory()->create(['name' => 'Other Co']);
        Project::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Other Project']);

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $targetOrg, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->assertSuccessful()
            ->assertSee('Target Project')
            ->assertDontSee('Other Project');
    }

    public function test_super_admin_without_an_active_session_is_denied(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertFalse(OrganizationSupportView::userCanAccessPage());
        Livewire::test(OrganizationSupportView::class)->assertForbidden();
    }

    public function test_a_non_super_admin_is_denied_regardless_of_session_state(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        Livewire::test(OrganizationSupportView::class)->assertForbidden();
    }

    public function test_ending_the_session_clears_it_and_redirects(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $this->actingAs($admin);
        SupportSessionContext::start($admin, $organization, 'Support request');

        Livewire::test(OrganizationSupportView::class)
            ->call('endSession')
            ->assertRedirect();

        $this->assertNull(SupportSessionContext::current($admin));
    }
}
