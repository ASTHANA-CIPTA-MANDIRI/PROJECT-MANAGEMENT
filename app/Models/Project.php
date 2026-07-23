<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Project extends Model implements HasMedia
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
            'name' => $this->name,
            'description' => strip_tags((string) $this->description),
            'ticket_prefix' => $this->ticket_prefix,
        ];
    }

    protected $fillable = [
        'name', 'description', 'status_id', 'owner_id', 'ticket_prefix',
        'status_type', 'type',
    ];

    protected $appends = [
        'cover',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id', 'id')->withTrashed();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id')->withPivot(['role']);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'project_id', 'id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TicketStatus::class, 'project_id', 'id');
    }

    public function epics(): HasMany
    {
        return $this->hasMany(Epic::class, 'project_id', 'id');
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class, 'project_id', 'id');
    }

    public function epicsFirstDate(): Attribute
    {
        return new Attribute(
            get: function () {
                $firstEpic = $this->epics()->orderBy('starts_at')->first();
                if ($firstEpic) {
                    return $firstEpic->starts_at;
                }

                return now();
            }
        );
    }

    public function epicsLastDate(): Attribute
    {
        return new Attribute(
            get: function () {
                $firstEpic = $this->epics()->orderBy('ends_at', 'desc')->first();
                if ($firstEpic) {
                    return $firstEpic->ends_at;
                }

                return now();
            }
        );
    }

    public function contributors(): Attribute
    {
        return new Attribute(
            get: function () {
                $users = $this->users;
                $users->push($this->owner);

                return $users->unique('id');
            }
        );
    }

    public function cover(): Attribute
    {
        return new Attribute(
            get: fn () => $this->media('cover')?->first()?->getFullUrl()
                ??
                'https://ui-avatars.com/api/?background=3f84f3&color=ffffff&name='.$this->name
        );
    }

    public function currentSprint(): Attribute
    {
        return new Attribute(
            get: fn () => $this->sprints()
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->first()
        );
    }

    public function nextSprint(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->currentSprint) {
                    return $this->sprints()
                        ->whereNull('started_at')
                        ->whereNull('ended_at')
                        ->where('starts_at', '>=', $this->currentSprint->ends_at)
                        ->orderBy('starts_at')
                        ->first();
                }

                return null;
            }
        );
    }

    /**
     * Aggregate statistics for the project, cached for one hour so the view
     * that displays them does not recompute the counts on every request.
     *
     * @return array{tickets:int, contributors:int, sprints:int, epics:int, logged_hours:float}
     */
    public function statistics(): array
    {
        return Cache::remember($this->statisticsCacheKey(), 3600, function () {
            $ticketIds = $this->tickets()->pluck('id');

            return [
                'tickets' => $ticketIds->count(),
                'contributors' => $this->contributors->count(),
                'sprints' => $this->sprints()->count(),
                'epics' => $this->epics()->count(),
                'logged_hours' => (float) TicketHour::whereIn('ticket_id', $ticketIds)->sum('value'),
            ];
        });
    }

    /**
     * Cache key holding this project's statistics.
     */
    public function statisticsCacheKey(): string
    {
        return "project:{$this->id}:statistics";
    }

    /**
     * Invalidate the cached statistics (call when tickets/hours change).
     */
    public function forgetStatistics(): void
    {
        Cache::forget($this->statisticsCacheKey());
    }
}
