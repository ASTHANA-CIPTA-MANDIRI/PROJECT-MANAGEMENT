<?php

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private function attachMember(Project $project, int|User $user, string $role = 'employee'): void
    {
        DB::table('project_users')->insert([
            'project_id' => $project->id,
            'user_id' => $user instanceof User ? $user->id : $user,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // 1. Empty database
    public function test_empty_database_creates_no_organization(): void
    {
        $this->artisan('organizations:backfill')->assertSuccessful();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_users', 0);
    }

    // 2 & 3. Existing / multiple projects get assigned to the Default Organization
    public function test_multiple_projects_are_assigned_to_default_organization(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $this->artisan('organizations:backfill')->assertSuccessful();

        $organization = Organization::where('name', 'Default Organization')->firstOrFail();

        $this->assertDatabaseHas('projects', ['id' => $projectA->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('projects', ['id' => $projectB->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseCount('organizations', 1);
    }

    // 4. Multiple project members + 5. project owner both get organization membership
    public function test_owner_and_members_receive_organization_membership(): void
    {
        $owner = User::factory()->create();
        $memberA = User::factory()->create();
        $memberB = User::factory()->create();

        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $this->attachMember($project, $memberA);
        $this->attachMember($project, $memberB);

        $this->artisan('organizations:backfill')->assertSuccessful();

        $organization = Organization::where('name', 'Default Organization')->firstOrFail();

        foreach ([$owner, $memberA, $memberB] as $user) {
            $this->assertDatabaseHas('organization_users', [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);
        }
    }

    // Unrelated user (no project ownership/membership) must NOT be enrolled.
    public function test_user_unrelated_to_any_project_is_not_enrolled(): void
    {
        $unrelated = User::factory()->create();
        Project::factory()->create();

        $this->artisan('organizations:backfill')->assertSuccessful();

        $organization = Organization::where('name', 'Default Organization')->firstOrFail();

        $this->assertDatabaseMissing('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $unrelated->id,
        ]);
    }

    // 6. A project whose owner was soft-deleted must not be treated as orphaned
    // (User uses SoftDeletes; belongsTo('owner') applies User's global scope
    // by default, which would otherwise false-positive this as "no valid owner").
    public function test_project_with_soft_deleted_owner_is_not_treated_as_orphaned(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $owner->delete();

        $this->artisan('organizations:backfill')->assertSuccessful();

        $organization = Organization::where('name', 'Default Organization')->firstOrFail();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);
    }

    // 7. A project with no project_users rows at all — only the owner exists.
    public function test_project_without_any_project_users_rows_still_backfills_owner(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->assertDatabaseCount('project_users', 0);

        $this->artisan('organizations:backfill')->assertSuccessful();

        $organization = Organization::where('name', 'Default Organization')->firstOrFail();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);
    }

    // 8. Existing organization (pre-created under the same name) is reused, not duplicated.
    public function test_pre_existing_default_organization_is_reused(): void
    {
        $existing = Organization::factory()->create(['name' => 'Default Organization']);
        $project = Project::factory()->create();

        $this->artisan('organizations:backfill')->assertSuccessful();

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'organization_id' => $existing->id]);
    }

    // 9. Existing membership is left alone, not duplicated.
    public function test_pre_existing_membership_is_not_duplicated(): void
    {
        $organization = Organization::factory()->create(['name' => 'Default Organization']);
        $owner = User::factory()->create();
        Project::factory()->create(['owner_id' => $owner->id]);
        $organization->users()->attach($owner);

        $this->artisan('organizations:backfill')->assertSuccessful();

        $this->assertDatabaseCount('organization_users', 1);
    }

    // 10. Running backfill twice is idempotent.
    public function test_running_backfill_twice_makes_no_further_changes(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $this->attachMember($project, $member);

        $this->artisan('organizations:backfill')->assertSuccessful();

        $organizationCountAfterFirst = Organization::count();
        $membershipCountAfterFirst = DB::table('organization_users')->count();

        $this->artisan('organizations:backfill')->assertSuccessful();

        $this->assertSame($organizationCountAfterFirst, Organization::count());
        $this->assertSame($membershipCountAfterFirst, DB::table('organization_users')->count());
    }

    // A second run also picks up drift: a new project/member added after the first run.
    public function test_second_run_picks_up_projects_and_members_added_after_first_run(): void
    {
        Project::factory()->create();
        $this->artisan('organizations:backfill')->assertSuccessful();

        $organization = Organization::where('name', 'Default Organization')->firstOrFail();
        $newOwner = User::factory()->create();
        $newProject = Project::factory()->create(['owner_id' => $newOwner->id]);

        $this->artisan('organizations:backfill')->assertSuccessful();

        $this->assertDatabaseHas('projects', ['id' => $newProject->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $newOwner->id,
        ]);
    }

    // 11. Dry-run must never mutate the database.
    public function test_dry_run_does_not_change_the_database(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $this->attachMember($project, $member);

        $this->artisan('organizations:backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_users', 0);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'organization_id' => null]);
    }

    public function test_dry_run_on_empty_database_reports_nothing_to_do(): void
    {
        $this->artisan('organizations:backfill', ['--dry-run' => true])
            ->expectsOutputToContain('Tidak ada yang perlu di-backfill.')
            ->assertSuccessful();
    }

    // 12. Failure rollback: a chunk that hits a genuine constraint violation
    // rolls back entirely rather than leaving partial rows behind. Simulated
    // with a project_users row pointing at a user_id that no longer resolves
    // (data corruption, e.g. a hard-deleted user row bypassing the app) —
    // organization_users.user_id is FK-constrained, so inserting a membership
    // row for it fails for real, inside the same chunk as a perfectly valid
    // owner.
    public function test_membership_chunk_rolls_back_entirely_on_fk_violation(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $ghostUserId = $owner->id + 999999;

        DB::statement('PRAGMA foreign_keys = OFF');
        $this->attachMember($project, $ghostUserId);
        DB::statement('PRAGMA foreign_keys = ON');

        $this->artisan('organizations:backfill')->assertFailed();

        // The whole membership chunk (owner + ghost) must have rolled back —
        // the owner must not be left with a partial membership row despite
        // being valid on its own.
        $this->assertDatabaseMissing('organization_users', ['user_id' => $owner->id]);
        $this->assertDatabaseCount('organization_users', 0);
    }

    // 13. Duplicate prevention is enforced at the database level too.
    public function test_organization_users_unique_constraint_prevents_duplicates(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        DB::table('organization_users')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('organization_users')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
