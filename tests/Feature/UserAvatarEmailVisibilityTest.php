<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The avatar popover (components.user-avatar) is rendered for any ticket
 * participant - owner, responsible, subscriber, commenter - wherever the
 * viewer has ticket access, which is broader than "should know this
 * person's email address". The email line is gated separately.
 */
class UserAvatarEmailVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function render(User $target): string
    {
        return view('components.user-avatar', ['user' => $target])->render();
    }

    public function test_a_user_without_the_view_user_permission_cannot_see_someone_elses_email(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create(['email' => 'secret@example.com']);
        $this->actingAs($viewer);

        $this->assertStringNotContainsString('secret@example.com', $this->render($target));
    }

    public function test_a_user_can_always_see_their_own_email(): void
    {
        $viewer = User::factory()->create(['email' => 'me@example.com']);
        $this->actingAs($viewer);

        $this->assertStringContainsString('me@example.com', $this->render($viewer));
    }

    public function test_a_user_with_the_view_user_permission_can_see_the_email(): void
    {
        Permission::firstOrCreate(['name' => 'View user']);
        $role = Role::create(['name' => 'User viewer']);
        $role->syncPermissions(['View user']);

        $viewer = User::factory()->create();
        $viewer->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $target = User::factory()->create(['email' => 'secret@example.com']);
        $this->actingAs($viewer->fresh());

        $this->assertStringContainsString('secret@example.com', $this->render($target));
    }
}
