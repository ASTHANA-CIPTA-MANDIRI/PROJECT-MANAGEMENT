<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * M-3: users.email and projects.ticket_prefix keep a plain unique index while
 * their models use SoftDeletes, so a value held by a soft-deleted row could
 * either lock forever or (worse) pass validation and hit that raw index on
 * save - an uncaught QueryException, HTTP 500. UniqueAmongTrashedRule (used by
 * UserResource, ProjectForm and ProjectRequest) closes this by always
 * catching the conflict, with a message that tells trashed and active
 * conflicts apart and points at restoring when that is the fix. See
 * docs/soft-deletes.md.
 */
class SoftDeleteUniqueValueTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    // -------------------------------------------------------------- User/email

    public function test_creating_a_user_with_a_trashed_users_email_is_rejected_not_a_500(): void
    {
        $admin = $this->userWithPermissions(['List users', 'Create user']);
        $old = User::factory()->create(['email' => 'dup@example.com']);
        $old->delete();
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New Person', 'email' => 'dup@example.com'])
            ->call('create')
            ->assertHasFormErrors(['email']);

        // No new row was inserted, and the trashed row is untouched.
        $this->assertSame(2, User::withTrashed()->count());
        $this->assertNotNull($old->fresh()->deleted_at);
    }

    public function test_creating_a_user_with_an_active_users_email_is_rejected_normally(): void
    {
        $admin = $this->userWithPermissions(['List users', 'Create user']);
        User::factory()->create(['email' => 'taken@example.com']);
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New Person', 'email' => 'taken@example.com'])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_editing_a_user_to_keep_their_own_email_is_allowed(): void
    {
        $admin = $this->userWithPermissions(['List users', 'View user', 'Update user']);
        $target = $this->userWithPermissions([]);
        $target->update(['email' => 'self@example.com']);
        $roleId = $target->roles()->value('roles.id');
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['name' => 'Renamed', 'email' => 'self@example.com', 'roles' => [$roleId]])
            ->call('save')
            ->assertHasNoFormErrors(['email']);

        $this->assertSame('Renamed', $target->fresh()->name);
    }

    public function test_after_restoring_a_user_their_email_is_protected_normally_again(): void
    {
        $admin = $this->userWithPermissions(['List users', 'Create user']);
        $old = User::factory()->create(['email' => 'dup@example.com']);
        $old->delete();
        $old->restore();
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New Person', 'email' => 'dup@example.com'])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertSame(2, User::withTrashed()->count());
        $this->assertNull($old->fresh()->deleted_at);
    }

    // --------------------------------------------------------- Project/prefix

    public function test_creating_a_project_with_a_trashed_projects_prefix_is_rejected_not_a_500(): void
    {
        $this->actingWithApi(['Create project']);
        $status = ProjectStatus::factory()->create();
        $old = Project::factory()->create(['ticket_prefix' => 'DUP', 'status_id' => $status->id]);
        $old->delete();

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'New project', 'ticket_prefix' => 'DUP',
            'status_id' => $status->id, 'type' => 'kanban', 'status_type' => 'default',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['ticket_prefix']);
        $this->assertSame(1, Project::withTrashed()->count());
        $this->assertNotNull($old->fresh()->deleted_at);
    }

    public function test_creating_a_project_with_an_active_projects_prefix_is_rejected_normally(): void
    {
        $this->actingWithApi(['Create project']);
        $status = ProjectStatus::factory()->create();
        Project::factory()->create(['ticket_prefix' => 'ABC', 'status_id' => $status->id]);

        $this->postJson('/api/v1/projects', [
            'name' => 'New project', 'ticket_prefix' => 'ABC',
            'status_id' => $status->id, 'type' => 'kanban', 'status_type' => 'default',
        ])->assertStatus(422)->assertJsonValidationErrors(['ticket_prefix']);

        $this->assertSame(1, Project::withTrashed()->count());
    }

    public function test_after_restoring_a_project_reusing_its_prefix_is_rejected_normally_again(): void
    {
        $this->actingWithApi(['Create project']);
        $status = ProjectStatus::factory()->create();
        $old = Project::factory()->create(['ticket_prefix' => 'DUP', 'status_id' => $status->id]);
        $old->delete();
        $old->restore();

        $this->postJson('/api/v1/projects', [
            'name' => 'New project', 'ticket_prefix' => 'DUP',
            'status_id' => $status->id, 'type' => 'kanban', 'status_type' => 'default',
        ])->assertStatus(422)->assertJsonValidationErrors(['ticket_prefix']);

        $this->assertSame(1, Project::withTrashed()->count());
        $this->assertNull($old->fresh()->deleted_at);
    }

    private function actingWithApi(array $permissions): User
    {
        $user = $this->userWithPermissions($permissions);
        $this->actingAs($user);

        return $user;
    }
}
