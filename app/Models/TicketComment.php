<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class TicketComment extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $fillable = [
        'user_id', 'ticket_id', 'content',
    ];

    /**
     * Sanitize rich-text content on write so stored (and later rendered) HTML
     * can never carry a script/XSS payload, regardless of the write path.
     */
    protected function content(): Attribute
    {
        return Attribute::set(fn (?string $value) => HtmlSanitizer::clean($value));
    }

    /**
     * The data indexed for full-text search (Laravel Scout).
     *
     * project_id is denormalized onto this table (see the
     * add_project_id_to_ticket_comments_table migration and
     * TicketCommentObserver::creating()) so SearchService can scope comment
     * search directly by project_id instead of first pulling every
     * accessible project's ticket ids into memory.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'content' => strip_tags((string) $this->content),
            'ticket_id' => $this->ticket_id,
            'project_id' => $this->project_id,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }
}
