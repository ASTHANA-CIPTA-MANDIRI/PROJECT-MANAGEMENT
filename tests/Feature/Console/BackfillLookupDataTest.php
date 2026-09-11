<?php

namespace Tests\Feature\Console;

use App\Models\Activity;
use App\Models\Organization;
use App\Models\TicketPriority;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillLookupDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_reports_nothing_to_backfill(): void
    {
        $this->artisan('lookup-data:backfill')
            ->expectsOutputToContain('Tidak ada yang perlu di-backfill.')
            ->assertSuccessful();
    }

    public function test_fails_without_a_default_organization_when_there_is_data_to_backfill(): void
    {
        Activity::factory()->create(['organization_id' => null]);

        $this->artisan('lookup-data:backfill')->assertFailed();
    }

    public function test_assigns_organization_less_activities_to_the_default_organization(): void
    {
        $organization = Organization::factory()->create(['name' => 'Default Organization']);
        $activity = Activity::factory()->create(['organization_id' => null]);

        $this->artisan('lookup-data:backfill')->assertSuccessful();

        $this->assertSame($organization->id, $activity->fresh()->organization_id);
    }

    public function test_an_activity_already_assigned_to_an_organization_is_left_alone(): void
    {
        Organization::factory()->create(['name' => 'Default Organization']);
        $ownOrganization = Organization::factory()->create();
        $activity = Activity::factory()->create(['organization_id' => $ownOrganization->id]);

        $this->artisan('lookup-data:backfill')->assertSuccessful();

        $this->assertSame($ownOrganization->id, $activity->fresh()->organization_id);
    }

    public function test_a_soft_deleted_activity_is_still_backfilled(): void
    {
        $organization = Organization::factory()->create(['name' => 'Default Organization']);
        $activity = Activity::factory()->create(['organization_id' => null]);
        $activity->delete();

        $this->artisan('lookup-data:backfill')->assertSuccessful();

        $this->assertSame($organization->id, $activity->fresh()->organization_id);
    }

    public function test_dry_run_does_not_change_the_database(): void
    {
        Organization::factory()->create(['name' => 'Default Organization']);
        $activity = Activity::factory()->create(['organization_id' => null]);

        $this->artisan('lookup-data:backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($activity->fresh()->organization_id);
    }

    /**
     * Fase 3B — TicketType and TicketPriority joined MODELS alongside
     * Activity; one pass over the same command backfills all three, not
     * just the first model that was wired.
     */
    public function test_ticket_types_and_priorities_are_backfilled_in_the_same_pass(): void
    {
        $organization = Organization::factory()->create(['name' => 'Default Organization']);
        $type = TicketType::factory()->create(['organization_id' => null]);
        $priority = TicketPriority::factory()->create(['organization_id' => null]);

        $this->artisan('lookup-data:backfill')->assertSuccessful();

        $this->assertSame($organization->id, $type->fresh()->organization_id);
        $this->assertSame($organization->id, $priority->fresh()->organization_id);
    }
}
