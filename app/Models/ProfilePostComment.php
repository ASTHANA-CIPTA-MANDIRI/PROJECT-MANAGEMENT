<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfilePostComment extends Model
{
    protected $fillable = ['profile_post_id', 'user_id', 'body'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(ProfilePost::class, 'profile_post_id');
    }

    public function user(): BelongsTo
    {
        // withTrashed: a removed account's posts stay readable instead of breaking the page.
        return $this->belongsTo(User::class)->withTrashed();
    }
}
