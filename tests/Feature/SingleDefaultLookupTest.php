<?php

namespace Tests\Feature;

use App\Models\ProjectStatus;
use App\Models\TicketPriority;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProjectStatus, TicketType and TicketPriority used to only get their
 * "single default" invariant enforced from Filament page hooks
 * (afterCreate/afterSave), so any other write path (seeders, jobs, tinker)
 * could leave two rows with is_default = true. ProjectStatusObserver,
 * TicketTypeObserver and TicketPriorityObserver now enforce it at the model
 * layer, mirroring the existing TicketStatusObserver.
 */
class SingleDefaultLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_a_project_status_as_default_unsets_the_previous_one(): void
    {
        $first = ProjectStatus::factory()->create(['is_default' => true]);
        $second = ProjectStatus::factory()->create(['is_default' => true]);

        $this->assertFalse((bool) $first->fresh()->is_default);
        $this->assertTrue((bool) $second->fresh()->is_default);
        $this->assertSame(1, ProjectStatus::where('is_default', true)->count());
    }

    public function test_setting_a_ticket_type_as_default_unsets_the_previous_one(): void
    {
        $first = TicketType::factory()->create(['is_default' => true]);
        $second = TicketType::factory()->create(['is_default' => true]);

        $this->assertFalse((bool) $first->fresh()->is_default);
        $this->assertTrue((bool) $second->fresh()->is_default);
        $this->assertSame(1, TicketType::where('is_default', true)->count());
    }

    public function test_setting_a_ticket_priority_as_default_unsets_the_previous_one(): void
    {
        $first = TicketPriority::factory()->create(['is_default' => true]);
        $second = TicketPriority::factory()->create(['is_default' => true]);

        $this->assertFalse((bool) $first->fresh()->is_default);
        $this->assertTrue((bool) $second->fresh()->is_default);
        $this->assertSame(1, TicketPriority::where('is_default', true)->count());
    }
}
