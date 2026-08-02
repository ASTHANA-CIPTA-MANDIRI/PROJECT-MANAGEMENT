<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_a_ticket_can_have_many_labels(): void
    {
        $ticket = Ticket::factory()->create();
        $labels = Label::factory()->count(3)->create();

        $ticket->labels()->sync($labels->pluck('id'));

        $this->assertCount(3, $ticket->fresh()->labels);
        $this->assertTrue($labels->first()->fresh()->tickets->contains($ticket));
    }

    public function test_labels_are_detached_when_a_label_is_deleted(): void
    {
        $ticket = Ticket::factory()->create();
        $label = Label::factory()->create();
        $ticket->labels()->attach($label);

        $label->delete();

        $this->assertCount(0, $ticket->fresh()->labels);
    }
}
