<?php

namespace Tests\Feature;

use App\Models\TicketStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TicketStatusObserver::saved() reorders colliding statuses by calling
 * save() on each one in a loop. Each of those save() calls used to
 * re-trigger the same observer, opening a new cascade on top of the one
 * already running — harmless with a couple of statuses, but query count and
 * recursion depth grew fast with a long chain. The cascade now runs inside
 * TicketStatus::withoutEvents() so a save() in the loop no longer re-enters
 * the observer.
 */
class TicketStatusOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_inserting_a_status_cascades_the_order_of_colliding_statuses(): void
    {
        $a = TicketStatus::factory()->create(['order' => 1]);
        $b = TicketStatus::factory()->create(['order' => 2]);
        $c = TicketStatus::factory()->create(['order' => 3]);

        $d = TicketStatus::factory()->create(['order' => 2]);

        $this->assertSame(1, $a->fresh()->order);
        $this->assertSame(2, $d->fresh()->order);
        $this->assertSame(3, $b->fresh()->order);
        $this->assertSame(4, $c->fresh()->order);
    }

    public function test_the_cascade_does_not_recursively_multiply_queries(): void
    {
        TicketStatus::factory()->count(20)->sequence(
            fn ($sequence) => ['order' => $sequence->index + 1]
        )->create();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        // Collides with the whole 20-status chain. Before the fix, each
        // save() in the cascade re-entered the observer and opened its own
        // nested cascade over the remaining chain, so the query count grew
        // combinatorially with chain length instead of linearly.
        TicketStatus::factory()->create(['order' => 1]);

        $this->assertLessThan(60, $queryCount);
    }
}
