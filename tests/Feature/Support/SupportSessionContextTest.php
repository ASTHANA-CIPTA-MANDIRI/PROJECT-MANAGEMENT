<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\SupportSessionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportSessionContextTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    public function test_start_creates_a_session_expiring_about_an_hour_from_now_and_current_returns_it(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start($admin, $organization, 'Debugging a ticket for the customer');

        $this->assertTrue($session->organization->is($organization));
        $this->assertSame('Debugging a ticket for the customer', $session->reason);
        $this->assertNull($session->ended_at);
        $this->assertTrue($session->expires_at->between(now()->addMinutes(59), now()->addMinutes(61)));

        $current = SupportSessionContext::current($admin);
        $this->assertNotNull($current);
        $this->assertTrue($current->is($session));
    }

    public function test_current_is_null_for_a_non_super_admin_even_with_a_valid_session_key(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = SupportSessionContext::start($admin, $organization, 'Support request');

        // Force the same session key another (non-Super-Admin) user would see.
        session(['support_session_id' => $session->id]);

        $regularUser = User::factory()->create();

        $this->assertNull(SupportSessionContext::current($regularUser));
    }

    public function test_current_is_null_once_expired_even_though_ended_at_is_still_null(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        SupportSessionContext::start($admin, $organization, 'Support request');

        $this->travel(61)->minutes();

        $this->assertNull(SupportSessionContext::current($admin));
    }

    public function test_stop_ends_the_session_and_current_returns_null_afterward(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        $session = SupportSessionContext::start($admin, $organization, 'Support request');

        SupportSessionContext::stop($admin);

        $this->assertNull(SupportSessionContext::current($admin));
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_a_session_started_by_one_super_admin_is_invisible_to_another_even_with_the_same_session_key(): void
    {
        $adminA = $this->superAdmin();
        $adminB = $this->superAdmin();
        $organization = Organization::factory()->create();

        $session = SupportSessionContext::start($adminA, $organization, 'Support request');

        // Force adminB's session to point at adminA's session row.
        session(['support_session_id' => $session->id]);

        $this->assertNull(SupportSessionContext::current($adminB));
    }

    public function test_starting_a_second_session_ends_the_first(): void
    {
        $admin = $this->superAdmin();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $first = SupportSessionContext::start($admin, $organizationA, 'First reason');
        $second = SupportSessionContext::start($admin, $organizationB, 'Second reason');

        $this->assertNotNull($first->fresh()->ended_at);
        $this->assertNull($second->fresh()->ended_at);

        $current = SupportSessionContext::current($admin);
        $this->assertTrue($current->is($second));
    }
}
