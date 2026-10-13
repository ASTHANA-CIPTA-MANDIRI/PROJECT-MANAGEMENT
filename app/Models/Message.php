<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use Prunable;

    protected $fillable = ['conversation_id', 'user_id', 'body'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Retention is opt-in (CHAT_RETENTION_DAYS), like the profile feed: by
     * default nothing is deleted. The daily `model:prune` in the schedule
     * picks this up when it is set.
     */
    public function prunable(): Builder
    {
        $days = (int) config('app.chat_retention_days');

        return $days > 0
            ? static::query()->where('created_at', '<', now()->subDays($days))
            : static::query()->whereRaw('1 = 0');
    }

    /** Pruning a message also removes its files from disk (rows cascade). */
    protected function pruning(): void
    {
        $this->attachments->each->purge();
    }
}
