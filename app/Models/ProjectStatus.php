<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectStatus extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'color', 'is_default', 'organization_id',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'status_id', 'id')->withTrashed();
    }
}
