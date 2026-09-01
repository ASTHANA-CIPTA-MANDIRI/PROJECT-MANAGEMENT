<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for query paths that run often but only had single-column or no
 * index to lean on:
 * - Kanban/Scrum board filters project_id + status_id together, but only
 *   single-column indexes exist on each (from their FK constraints).
 * - tickets.due_date is range/equality-scanned daily by
 *   tickets:due-date-reminders with no index at all.
 * - created_at on activities/comments/hours is range-scanned by scheduled
 *   cleanup/report commands with no index.
 *
 * label_ticket.ticket_id is NOT added here: InnoDB already auto-creates
 * label_ticket_ticket_id_foreign (ticket_id-leading) to enforce that FK, so
 * Ticket::labels() already has a usable index — confirmed via SHOW INDEX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['project_id', 'status_id']);
            $table->index('due_date');
        });

        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('ticket_hours', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status_id']);
            $table->dropIndex(['due_date']);
        });

        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('ticket_hours', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
