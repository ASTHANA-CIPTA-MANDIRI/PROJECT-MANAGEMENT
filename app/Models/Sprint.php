<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sprint extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'starts_at', 'ends_at', 'description',
        'project_id', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'sprint_id', 'id');
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class, 'epic_id', 'id');
    }

    /**
     * Whole days left in a running sprint, counting today as one of them:
     * 1 on the closing day, 0 the day after, negative once overdue. Null when
     * the sprint has not started or has already been closed.
     *
     * Counted from the start of today rather than from `now()`, so the number
     * does not tick over halfway through the day, and signed — diffInDays()
     * is absolute by default, which used to make an overdue sprint report a
     * growing number of days *remaining*.
     */
    public function remaining(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->starts_at && $this->ends_at && $this->started_at && ! $this->ended_at) {
                    return now()->startOfDay()->diffInDays($this->ends_at, false) + 1;
                }

                return null;
            }
        );
    }
}
