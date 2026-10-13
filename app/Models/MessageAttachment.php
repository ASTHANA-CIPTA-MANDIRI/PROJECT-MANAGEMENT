<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    public const DISK = 'local';

    protected $fillable = ['message_id', 'conversation_id', 'path', 'original_name', 'mime', 'size', 'is_image', 'purged_at'];

    protected $casts = [
        'is_image' => 'boolean',
        'purged_at' => 'datetime',
        'size' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isAvailable(): bool
    {
        return $this->purged_at === null && $this->path !== null;
    }

    public function url(): string
    {
        return route('chat.attachment', $this);
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? round($this->size / 1048576, 1).' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }

    /** Short label for the file-type tile (PDF, DOC, XLS...). */
    public function extensionLabel(): string
    {
        return strtoupper(pathinfo($this->original_name, PATHINFO_EXTENSION) ?: 'FILE');
    }

    /** Removes the file from disk, keeping the row as an "expired" marker. */
    public function purge(): void
    {
        // Only mark the row once the file is really gone, so a failed delete is
        // retried by the next run instead of leaving an orphan nobody tracks.
        if ($this->path && ! Storage::disk(self::DISK)->delete($this->path) && Storage::disk(self::DISK)->exists($this->path)) {
            return;
        }

        $this->forceFill(['path' => null, 'purged_at' => now()])->save();
    }

    /**
     * Deletes the files of attachments older than the retention period.
     * 0 (or less) keeps them forever. Run daily from the scheduler.
     */
    public static function purgeExpired(): int
    {
        $days = (int) config('system.chat.attachment_days');

        if ($days <= 0) {
            return 0;
        }

        $purged = 0;

        static::query()
            ->whereNull('purged_at')
            ->where('created_at', '<', now()->subDays($days))
            ->chunkById(200, function ($attachments) use (&$purged) {
                foreach ($attachments as $attachment) {
                    $attachment->purge();

                    if ($attachment->purged_at !== null) {
                        $purged++;
                    }
                }
            });

        return $purged;
    }
}
