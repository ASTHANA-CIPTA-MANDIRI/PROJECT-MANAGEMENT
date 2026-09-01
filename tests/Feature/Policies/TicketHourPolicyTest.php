<?php

namespace Tests\Feature\Policies;

use App\Models\TicketHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

class TicketHourPolicyTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    // ------------------------------------------------------------- viewAny

    public function test_listing_requires_the_list_timesheet_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['List timesheet data'])->can('viewAny', TicketHour::class));
    }

    public function test_listing_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('viewAny', TicketHour::class));
    }

    // ---------------------------------------------------------------- view

    public function test_the_owner_can_view_their_own_logged_hours(): void
    {
        $user = $this->userWithPermissions(['List timesheet data']);
        $ticketHour = TicketHour::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->can('view', $ticketHour));
    }

    public function test_an_unrelated_user_cannot_view_someone_elses_logged_hours(): void
    {
        $user = $this->userWithPermissions(['List timesheet data']);
        $ticketHour = TicketHour::factory()->create();

        $this->assertFalse($user->can('view', $ticketHour));
    }

    // -------------------------------------------------------------- create

    public function test_creating_requires_the_list_timesheet_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['List timesheet data'])->can('create', TicketHour::class));
    }

    public function test_creating_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('create', TicketHour::class));
    }

    // -------------------------------------------------------------- update

    public function test_the_owner_can_update_their_own_logged_hours(): void
    {
        $user = $this->userWithPermissions(['List timesheet data']);
        $ticketHour = TicketHour::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->can('update', $ticketHour));
    }

    public function test_an_unrelated_user_cannot_update_someone_elses_logged_hours(): void
    {
        $user = $this->userWithPermissions(['List timesheet data']);
        $ticketHour = TicketHour::factory()->create();

        $this->assertFalse($user->can('update', $ticketHour));
    }

    // -------------------------------------------------------------- delete

    public function test_the_owner_can_delete_their_own_logged_hours(): void
    {
        $user = $this->userWithPermissions(['List timesheet data']);
        $ticketHour = TicketHour::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->can('delete', $ticketHour));
    }

    public function test_an_unrelated_user_cannot_delete_someone_elses_logged_hours(): void
    {
        $user = $this->userWithPermissions(['List timesheet data']);
        $ticketHour = TicketHour::factory()->create();

        $this->assertFalse($user->can('delete', $ticketHour));
    }

    // ----------------------------------------------------------- deleteAny

    public function test_bulk_deleting_requires_the_list_timesheet_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['List timesheet data'])->can('deleteAny', TicketHour::class));
    }

    public function test_bulk_deleting_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('deleteAny', TicketHour::class));
    }
}
