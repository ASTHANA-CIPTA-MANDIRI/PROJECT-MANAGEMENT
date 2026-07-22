<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use App\Notifications\UserCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- creation hooks

    public function test_a_db_user_receives_a_creation_token(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'type' => 'db',
        ]);

        $this->assertNotNull($user->creation_token);
    }

    public function test_a_db_user_gets_a_hashed_random_password(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Jane',
            'email' => 'jane2@example.com',
            'type' => 'db',
        ]);

        $this->assertNotNull($user->password);
        $this->assertNotSame('', $user->password);
    }

    public function test_a_db_user_is_notified_on_creation(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Jane',
            'email' => 'jane3@example.com',
            'type' => 'db',
        ]);

        Notification::assertSentTo($user, UserCreatedNotification::class);
    }

    public function test_a_non_db_user_gets_no_creation_token(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Ext',
            'email' => 'ext@example.com',
            'type' => 'social',
        ]);

        $this->assertNull($user->creation_token);
        Notification::assertNothingSent();
    }

    // ------------------------------------------------------- panel access

    public function test_a_user_without_roles_cannot_access_the_panel(): void
    {
        $this->assertFalse(User::factory()->create()->canAccessFilament());
    }

    public function test_a_user_with_a_role_can_access_the_panel(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([\App\Models\Role::create(['name' => 'Member'])]);

        $this->assertTrue($user->fresh()->canAccessFilament());
    }

    // ------------------------------------------------------- relationships

    public function test_it_has_many_owned_projects(): void
    {
        $user = User::factory()->create();
        Project::factory()->count(2)->create(['owner_id' => $user->id]);

        $this->assertCount(2, $user->projectsOwning);
    }

    public function test_it_has_many_affected_projects(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'member']);

        $this->assertCount(1, $user->projectsAffected);
        $this->assertSame('member', $user->projectsAffected->first()->pivot->role);
    }

    public function test_it_can_favorite_projects(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $user->favoriteProjects()->attach($project->id);

        $this->assertTrue($user->favoriteProjects->contains($project));
    }

    public function test_it_has_many_owned_tickets(): void
    {
        $user = User::factory()->create();
        Ticket::factory()->count(2)->create(['owner_id' => $user->id]);

        $this->assertCount(2, $user->ticketsOwned);
    }

    public function test_it_has_many_responsible_tickets(): void
    {
        $user = User::factory()->create();
        Ticket::factory()->count(3)->create(['responsible_id' => $user->id]);

        $this->assertCount(3, $user->ticketsResponsible);
    }

    public function test_it_has_many_logged_hours(): void
    {
        $user = User::factory()->create();
        TicketHour::factory()->count(2)->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->hours);
    }

    // --------------------------------------------------------- logged hours

    public function test_total_logged_in_hours_sums_all_entries(): void
    {
        $user = User::factory()->create();
        TicketHour::factory()->hours(3)->create(['user_id' => $user->id]);
        TicketHour::factory()->hours(1.5)->create(['user_id' => $user->id]);

        $this->assertEqualsWithDelta(4.5, $user->totalLoggedInHours, 0.001);
    }

    public function test_total_logged_in_hours_is_zero_without_entries(): void
    {
        $this->assertEqualsWithDelta(0, User::factory()->create()->totalLoggedInHours, 0.001);
    }

    // ------------------------------------------------------------ hygiene

    public function test_password_and_remember_token_are_hidden_from_serialization(): void
    {
        $user = User::factory()->create();

        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }

    public function test_it_soft_deletes(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }
}
