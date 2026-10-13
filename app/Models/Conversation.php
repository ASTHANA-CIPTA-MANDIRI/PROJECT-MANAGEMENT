<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

class Conversation extends Model
{
    protected $fillable = ['user_a_id', 'user_b_id'];

    // Microseconds: read markers are compared with last_message_at (see the migration).
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'last_message_at' => 'datetime',
        'a_read_at' => 'datetime',
        'b_read_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Rows cascade in the database; the files would otherwise stay on disk.
        static::deleting(fn (self $conversation) => \Illuminate\Support\Facades\Storage::disk(MessageAttachment::DISK)
            ->deleteDirectory('chat/'.$conversation->id));
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The pair's conversation (without creating it), whichever order the ids
     * come in.
     */
    public static function findBetween(int $one, int $two): ?self
    {
        [$a, $b] = $one < $two ? [$one, $two] : [$two, $one];

        return static::query()->where('user_a_id', $a)->where('user_b_id', $b)->first();
    }

    public static function between(int $one, int $two): self
    {
        [$a, $b] = $one < $two ? [$one, $two] : [$two, $one];

        try {
            return static::query()->firstOrCreate(['user_a_id' => $a, 'user_b_id' => $b]);
        } catch (QueryException $e) {
            // Two first messages at once (e.g. two tabs): the other request won
            // the unique (user_a_id, user_b_id) race. Anything else is rethrown.
            return static::query()->where('user_a_id', $a)->where('user_b_id', $b)->first() ?? throw $e;
        }
    }

    public function otherUserId(User $me): int
    {
        return $this->user_a_id === $me->id ? $this->user_b_id : $this->user_a_id;
    }

    public function scopeInvolving(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('user_a_id', $user->id)->orWhere('user_b_id', $user->id));
    }

    /**
     * Conversations whose latest message is from someone else and newer than
     * the user's own read marker.
     */
    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        $unreadSide = fn (string $idColumn, string $readColumn) => fn (Builder $query) => $query
            ->where($idColumn, $user->id)
            ->where(fn (Builder $query) => $query->whereNull($readColumn)->orWhereColumn($readColumn, '<', 'last_message_at'));

        return $query
            ->whereNotNull('last_message_at')
            ->where('last_sender_id', '!=', $user->id)
            ->where(fn (Builder $query) => $query
                ->where($unreadSide('user_a_id', 'a_read_at'))
                ->orWhere($unreadSide('user_b_id', 'b_read_at')));
    }

    public function isUnreadFor(User $user): bool
    {
        if ($this->last_message_at === null || $this->last_sender_id === $user->id) {
            return false;
        }

        $readAt = $this->user_a_id === $user->id ? $this->a_read_at : $this->b_read_at;

        return $readAt === null || $readAt->lt($this->last_message_at);
    }

    public function markReadFor(User $user): void
    {
        $column = $this->user_a_id === $user->id ? 'a_read_at' : 'b_read_at';

        if ($this->isUnreadFor($user)) {
            $this->forceFill([$column => now()])->saveQuietly();
        }
    }
}
