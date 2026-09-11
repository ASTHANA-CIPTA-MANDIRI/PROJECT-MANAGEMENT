<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\PlatformOrganizations;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Super Admin only, read-only, cross-organization view (ADR 0001,
 * "Platform" section). Authorization here is deliberately NOT
 * OrganizationContext/OrganizationPolicy — see the class docblock on
 * PlatformOrganizations for why — so these tests pin down that a plain
 * Organization member (even an Owner) is refused just like anyone else,
 * only User::isSuperAdmin() opens the page.
 */
class PlatformOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::create(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    // ----------------------------------------------------------- access

    public function test_super_admin_can_open_the_page(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformOrganizations::class)->assertSuccessful();
    }

    /**
     * The whole point of the page: every Organization is listed, including
     * one the Super Admin holds no membership in at all — proving this does
     * not go through OrganizationContext/OrganizationPolicy, both of which
     * are scoped to "organizations this user belongs to".
     */
    public function test_super_admin_sees_every_organization_including_ones_they_do_not_belong_to(): void
    {
        $admin = $this->superAdmin();

        $ownOrganization = Organization::factory()->create(['name' => 'Own Org']);
        $ownOrganization->users()->attach($admin->id, ['role' => 'member']);

        // Deliberately not attaching $admin to this organization at all.
        Organization::factory()->create(['name' => 'Stranger Org']);

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee('Own Org')
            ->assertSee('Stranger Org');
    }

    /**
     * A regular, non-Super-Admin panel user is refused — even one who owns
     * an organization of their own. Owning/managing an organization is a
     * different axis (OrganizationPolicy) from the platform-level access
     * this page requires.
     */
    public function test_a_non_super_admin_is_denied_access(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);

        Livewire::test(PlatformOrganizations::class)->assertForbidden();
    }

    public function test_opening_the_page_url_directly_without_super_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(PlatformOrganizations::getUrl())->assertForbidden();
    }

    /**
     * The navigation entry being hidden is a UX nicety only — the route
     * stays registered, so the page itself must still refuse a direct hit.
     * Mirrors CustomPageAuthorizationTest's equivalent guard for the
     * permission-based pages.
     */
    public function test_hiding_the_navigation_entry_is_not_the_authorization(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(PlatformOrganizations::userCanAccessPage());
        $this->assertNotNull(PlatformOrganizations::getUrl());
        Livewire::test(PlatformOrganizations::class)->assertForbidden();
    }

    // ----------------------------------------------------- trial status

    public function test_a_grandfathered_organization_is_labelled_grandfathered(): void
    {
        $this->actingAs($this->superAdmin());

        Organization::factory()->create(['name' => 'Grandfathered Co', 'trial_ends_at' => null]);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee(__('Grandfathered'));
    }

    public function test_an_organization_still_on_trial_shows_days_remaining(): void
    {
        $this->actingAs($this->superAdmin());

        Organization::factory()->create([
            'name' => 'Trialing Co',
            'trial_ends_at' => now()->addDays(3),
        ]);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee(__('Trial active (:days days left)', ['days' => 3]));
    }

    public function test_an_organization_past_its_trial_and_unsubscribed_shows_trial_ended(): void
    {
        $this->actingAs($this->superAdmin());

        Organization::factory()->trialExpired()->create(['name' => 'Expired Co']);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee(__('Trial ended'));
    }

    // ------------------------------------------------------- other columns

    public function test_owner_name_is_shown_on_the_page(): void
    {
        $admin = $this->superAdmin();
        $owner = User::factory()->create(['name' => 'Ollie Owner', 'email' => 'ollie@example.test']);
        $member = User::factory()->create();

        $organization = Organization::factory()->create(['name' => 'Staffed Co']);
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $organization->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($admin);

        Livewire::test(PlatformOrganizations::class)
            ->assertSuccessful()
            ->assertSee('Ollie Owner');
    }

    /**
     * assertSee() is unreliable for the other two values checked here: a
     * bare digit like the member count also matches dates elsewhere on the
     * page, and the email column renders as an obfuscated `mailto:` link
     * (the `@` is HTML-entity-encoded), so raw string matching against the
     * rendered HTML would not find it either. The underlying query's
     * computed values are asserted directly instead.
     */
    public function test_member_count_and_owner_are_computed_correctly(): void
    {
        $admin = $this->superAdmin();
        $owner = User::factory()->create(['name' => 'Ollie Owner', 'email' => 'ollie@example.test']);
        $member = User::factory()->create();

        $organization = Organization::factory()->create(['name' => 'Staffed Co']);
        $organization->users()->attach($owner->id, ['role' => 'owner']);
        $organization->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($admin);

        $page = new PlatformOrganizations;
        $method = new \ReflectionMethod($page, 'getTableQuery');
        $method->setAccessible(true);

        $record = $method->invoke($page)->whereKey($organization->id)->first();

        $this->assertSame(2, $record->users_count);
        $this->assertSame('Ollie Owner', $record->users->first()?->name);
        $this->assertSame('ollie@example.test', $record->users->first()?->email);
    }
}
