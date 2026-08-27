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

    /**
     * saved() used to re-run both the ordering cascade and the single-default
     * enforcement on every save, even when neither order nor is_default
     * changed (renaming a status, recoloring it, ...). Guarded on
     * wasRecentlyCreated/wasChanged() now, so an unrelated update runs no
     * TicketStatus queries at all.
     */
    public function test_saving_an_unrelated_field_runs_no_reordering_queries(): void
    {
        $status = TicketStatus::factory()->create(['order' => 1, 'name' => 'To do']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $status->update(['name' => 'Backlog']);

        // Exactly the UPDATE statement itself - no extra SELECT/UPDATE from
        // either cascade.
        $this->assertSame(1, $queryCount, 'An unrelated field update triggered TicketStatus reordering/default queries.');
    }

    /**
     * The single-default cascade must still run when an *existing* status is
     * edited to become the default (e.g. via the Filament edit form), not
     * only when a new one is created with is_default already set.
     */
    public function test_marking_an_existing_status_as_default_unsets_the_previous_one(): void
    {
        $first = TicketStatus::factory()->create(['is_default' => true]);
        $second = TicketStatus::factory()->create(['is_default' => false]);

        $second->update(['is_default' => true]);

        $this->assertFalse((bool) $first->fresh()->is_default);
        $this->assertTrue((bool) $second->fresh()->is_default);
    }

    /**
     * Reordering an existing status (no is_default change) must still
     * cascade the shift, matching the create-time behaviour covered above.
     */
    public function test_moving_an_existing_status_still_cascades_the_order(): void
    {
        $a = TicketStatus::factory()->create(['order' => 1]);
        $b = TicketStatus::factory()->create(['order' => 2]);
        $c = TicketStatus::factory()->create(['order' => 3]);

        $c->update(['order' => 1]);

        $this->assertSame(1, $c->fresh()->order);
        $this->assertSame(2, $a->fresh()->order);
        $this->assertSame(3, $b->fresh()->order);
    }
}
