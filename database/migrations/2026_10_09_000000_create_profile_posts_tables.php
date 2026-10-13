<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The profile feed: short posts shared with the author's current
     * Organization, plus likes and comments on them. organization_id is
     * nullable the same way projects.organization_id is (a user with no
     * Organization only ever sees their own posts).
     */
    public function up(): void
    {
        Schema::create('profile_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('profile_post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['profile_post_id', 'user_id']);
        });

        Schema::create('profile_post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_post_comments');
        Schema::dropIfExists('profile_post_likes');
        Schema::dropIfExists('profile_posts');
    }
};
