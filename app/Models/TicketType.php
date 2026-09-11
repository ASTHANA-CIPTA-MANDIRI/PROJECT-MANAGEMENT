<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketType extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'color', 'icon', 'is_default', 'organization_id',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'type_id', 'id')->withTrashed();
    }
}
