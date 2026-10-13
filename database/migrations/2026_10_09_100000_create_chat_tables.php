<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct messages between two users (the Inbox).
     *
     * One conversation row per pair, stored with user_a_id < user_b_id so the
     * pair is unique no matter who writes first. The list view never touches
     * the messages table: last_message_at / last_sender_id / last_message_preview
     * live on the conversation, and per-side read state is a timestamp
     * (a_read_at / b_read_at) instead of a flag on every message, so the
     * messages table can grow without the Inbox getting slower. Those
     * timestamps keep microseconds so a message arriving in the same second as a
     * read marker still counts as unread.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_b_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at', 6)->nullable();
            $table->string('last_message_preview', 120)->nullable();
            $table->timestamp('a_read_at', 6)->nullable();
            $table->timestamp('b_read_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['user_a_id', 'user_b_id']);
            $table->index(['user_a_id', 'last_message_at']);
            $table->index(['user_b_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
