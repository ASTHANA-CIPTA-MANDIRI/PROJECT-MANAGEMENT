<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketStatus extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'color', 'is_default', 'order',
        'project_id',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'status_id', 'id')->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }
}
