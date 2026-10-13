<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files and images sent in a chat. The file itself lives on the private
     * local disk (never publicly linkable) and is served through an
     * authorised route; it is purged after config('system.chat.attachment_days')
     * days. The row stays (purged_at set, path emptied) so the message can say
     * "attachment expired" instead of silently losing context.
     */
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('path')->nullable();
            $table->string('original_name', 120);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->boolean('is_image')->default(false);
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'purged_at', 'id']);
            $table->index(['purged_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
