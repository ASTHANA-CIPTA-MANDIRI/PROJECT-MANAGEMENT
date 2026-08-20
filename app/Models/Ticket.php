<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Ticket extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, Searchable, SoftDeletes;

    /**
     * The data indexed for full-text search (Laravel Scout).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'content' => strip_tags((string) $this->content),
            'project_id' => $this->project_id,
        ];
    }

    protected $fillable = [
        'name', 'content', 'owner_id', 'responsible_id',
        'status_id', 'project_id', 'code', 'order', 'type_id',
        'priority_id', 'estimation', 'epic_id', 'sprint_id', 'due_date',
    ];

    protected $casts = [
        'due_date' => 'date',
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
     * Tickets the given user may see: the ones they own or are responsible
     * for, plus every ticket of a project they own or belong to. Mirrors the
     * filter the dashboard tables use, so aggregations agree with listings.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('owner_id', $user->id)
            ->orWhere('responsible_id', $user->id)
            ->orWhereHas('project', fn (Builder $query) => $query->accessibleBy($user)));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id', 'id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'status_id', 'id')->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'id')->withTrashed();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'type_id', 'id')->withTrashed();
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'priority_id', 'id')->withTrashed();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class, 'ticket_id', 'id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'ticket_id', 'id');
    }

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_subscribers', 'ticket_id', 'user_id');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(TicketRelation::class, 'ticket_id', 'id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(TicketHour::class, 'ticket_id', 'id');
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class, 'epic_id', 'id');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class, 'sprint_id', 'id');
    }

    public function watchers(): Attribute
    {
        return new Attribute(
            get: function () {
                // ->project->users is Eloquent's cached relation collection,
                // not a copy: push()ing onto it would leave the owner and
                // responsible looking like project members to every other
                // reader of $project->users for the rest of the request.
                $users = $this->project->users->collect();
                $users->push($this->owner);
                if ($this->responsible) {
                    $users->push($this->responsible);
                }

                return $users->unique('id');
            }
        );
    }

    /**
     * True once the due date's day has fully passed. A ticket due "today" is
     * not yet overdue — only a due date strictly before today counts.
     */
    public function isOverdue(): Attribute
    {
        return new Attribute(
            get: fn () => $this->due_date !== null && $this->due_date->lt(now()->startOfDay())
        );
    }

    /**
     * Total hours logged against this ticket.
     *
     * Prefers the SQL aggregate when the query asked for it
     * (->withSum('hours', 'value')): summing in the database is one query for
     * the whole result set, where reading the relation pulls every hour row of
     * every ticket into memory to add up a single column. Falls back to the
     * relation so a plain Ticket instance still answers correctly.
     */
    private function loggedHoursValue(): float
    {
        if (array_key_exists('hours_sum_value', $this->attributes)) {
            return (float) $this->attributes['hours_sum_value'];
        }

        return (float) $this->hours->sum('value');
    }

    public function totalLoggedHours(): Attribute
    {
        return new Attribute(
            get: function () {
                $seconds = $this->loggedHoursValue() * 3600;

                return CarbonInterval::seconds($seconds)->cascade()->forHumans();
            }
        );
    }

    public function totalLoggedSeconds(): Attribute
    {
        return new Attribute(
            get: fn () => $this->loggedHoursValue() * 3600
        );
    }

    public function totalLoggedInHours(): Attribute
    {
        return new Attribute(
            get: fn () => $this->loggedHoursValue()
        );
    }

    public function estimationForHumans(): Attribute
    {
        return new Attribute(
            get: function () {
                return CarbonInterval::seconds($this->estimationInSeconds)->cascade()->forHumans();
            }
        );
    }

    public function estimationInSeconds(): Attribute
    {
        return new Attribute(
            get: function () {
                if (! $this->estimation) {
                    return null;
                }

                return $this->estimation * 3600;
            }
        );
    }

    public function estimationProgress(): Attribute
    {
        return new Attribute(
            get: function () {
                return (($this->totalLoggedSeconds ?? 0) / ($this->estimationInSeconds ?? 1)) * 100;
            }
        );
    }

    public function completudePercentage(): Attribute
    {
        return new Attribute(
            get: fn () => $this->estimationProgress
        );
    }
}
