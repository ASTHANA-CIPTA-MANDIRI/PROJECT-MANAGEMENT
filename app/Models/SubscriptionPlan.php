<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One purchasable subscription plan (price + duration). Written only by
 * App\Services\PlanManager (Super Admin, audited) and read through
 * App\Support\SubscriptionPlans. See the create_subscription_plans_table
 * migration for why `code` is immutable and a plan is never deleted.
 *
 * Exempt from Filament's Policy registration (PolicyRegistrationTest): no
 * Filament Resource exposes it; the Super Admin page that edits it authorizes
 * with isSuperAdmin() itself, like the Payments page.
 */
class SubscriptionPlan extends Model
{
    use HasFactory;

    /** Lower-case letters, digits, "_" and "-"; starts with a letter or digit. */
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    protected $fillable = [
        'code',
        'name',
        'amount',
        'duration_days',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'integer',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // Invoices reference plan_code forever; a plan can only be deactivated.
        static::deleting(function (): never {
            throw new LogicException('A subscription plan is never deleted, only deactivated.');
        });

        // The code is the identity other tables point at.
        static::updating(function (self $plan): void {
            if ($plan->isDirty('code')) {
                throw new LogicException('The code of a subscription plan cannot be changed.');
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
