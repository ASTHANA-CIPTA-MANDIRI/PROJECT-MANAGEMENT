<?php

namespace App\Models;

use App\Support\OrganizationContext;
use App\Support\TrialGate;
use App\Support\WidgetDataCache;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'ticket_id', 'value', 'comment', 'activity_id',
    ];

    public static function boot()
    {
        parent::boot();

        // Logged hours feed into Project::statistics() and the TimeLogged
        // dashboard widgets; keep both caches fresh.
        $forget = function (TicketHour $item) {
            $item->ticket?->project?->forgetStatistics();
            WidgetDataCache::invalidate();
        };
        static::created($forget);
        static::updated($forget);
        static::deleted($forget);
    }

    /**
     * Full-lock read gate for pages that aggregate a user's own hours
     * (timesheet dashboard + report widgets). They are only guarded by the
     * flat 'View timesheet dashboard' permission, which Gate::before()'s
     * model-class matching never sees, so an expired, unsubscribed trial
     * (TicketHour is in AuthServiceProvider::TRIAL_GATED_MODELS: read AND
     * write locked) would otherwise still read the numbers.
     *
     * Same rule as Gate::before(): no Organization in play (no membership,
     * Super Admin outside any Organization) or an active/grandfathered/
     * subscribed one changes nothing; only a locked current Organization
     * forces an empty result (not an error) so the page still renders.
     */
    public function scopeUnlessOrganizationLocked(Builder $query, User $user): Builder
    {
        $organization = OrganizationContext::current($user);

        if ($organization === null || TrialGate::active($organization)) {
            return $query;
        }

        return $query->whereRaw('0 = 1');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id', 'id');
    }

    public function forHumans(): Attribute
    {
        return new Attribute(
            get: function () {
                $seconds = $this->value * 3600;

                return CarbonInterval::seconds($seconds)->cascade()->forHumans();
            }
        );
    }
}
