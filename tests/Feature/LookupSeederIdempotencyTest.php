<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\TicketPriority;
use App\Models\TicketType;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\TicketPrioritySeeder;
use Database\Seeders\TicketTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TicketTypeSeeder, TicketPrioritySeeder and ActivitySeeder used to match
 * firstOrCreate() on the whole attribute array instead of just `name` (unlike
 * ProjectStatusSeeder / TicketStatusSeeder, which already matched by name
 * only). Re-running db:seed after an admin edited a color/is_default via the
 * UI would then insert a duplicate row carrying the old seed values instead
 * of leaving the edited one alone.
 */
class LookupSeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_type_seeder_does_not_duplicate_after_manual_edit(): void
    {
        $this->seed(TicketTypeSeeder::class);

        TicketType::where('name', 'Task')->update(['color' => '#123456']);

        $this->seed(TicketTypeSeeder::class);

        $this->assertSame(1, TicketType::where('name', 'Task')->count());
        $this->assertSame('#123456', TicketType::where('name', 'Task')->value('color'));
    }

    public function test_ticket_priority_seeder_does_not_duplicate_after_manual_edit(): void
    {
        $this->seed(TicketPrioritySeeder::class);

        TicketPriority::where('name', 'Normal')->update(['color' => '#123456']);

        $this->seed(TicketPrioritySeeder::class);

        $this->assertSame(1, TicketPriority::where('name', 'Normal')->count());
        $this->assertSame('#123456', TicketPriority::where('name', 'Normal')->value('color'));
    }

    public function test_activity_seeder_does_not_duplicate_after_manual_edit(): void
    {
        $this->seed(ActivitySeeder::class);

        Activity::where('name', 'Programming')->update(['description' => 'Edited by admin']);

        $this->seed(ActivitySeeder::class);

        $this->assertSame(1, Activity::where('name', 'Programming')->count());
        $this->assertSame('Edited by admin', Activity::where('name', 'Programming')->value('description'));
    }
}
