<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `content` mutator on Ticket and TicketComment is the single choke point
 * that keeps stored rich-text free of XSS, regardless of the write path.
 */
class ContentSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_content_is_sanitized_on_save(): void
    {
        $ticket = Ticket::factory()->create([
            'content' => '<p>Report</p><script>alert(document.cookie)</script>',
        ]);

        $this->assertStringNotContainsString('<script', $ticket->fresh()->content);
        $this->assertStringContainsString('Report', $ticket->fresh()->content);
    }

    public function test_ticket_comment_content_is_sanitized_on_save(): void
    {
        $comment = TicketComment::factory()->create([
            'content' => '<img src="x" onerror="steal()">note',
        ]);

        $stored = $comment->fresh()->content;
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringContainsString('note', $stored);
    }

    public function test_safe_formatting_survives(): void
    {
        $ticket = Ticket::factory()->create([
            'content' => '<p><strong>Important</strong></p>',
        ]);

        $this->assertStringContainsString('<strong>Important</strong>', $ticket->fresh()->content);
    }
}
