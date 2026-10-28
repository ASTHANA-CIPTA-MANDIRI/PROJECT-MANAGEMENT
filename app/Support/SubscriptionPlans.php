<?php

namespace App\Support;

use App\Models\SubscriptionPlan;

/**
 * Fase 8-9 - the single place that resolves a plan *code* (client-supplied,
 * e.g. a Livewire/form select value) into its server-authoritative price
 * and duration, read from the `subscription_plans` table. Mirrors the rest
 * of the codebase's "a client-supplied id is never trusted past the UI layer"
 * rule (CLAUDE.md): App\Http\Controllers\SubscriptionCheckoutController
 * never reads an amount from the request, only a plan code, which is
 * re-resolved through here immediately before use.
 *
 * Two questions, kept apart on purpose:
 *  - what may be BOUGHT / shown to buy (active plans only): {@see sellable()},
 *    {@see findSellable()}, {@see codes()};
 *  - what a code MEANS (any plan, including a deactivated one): {@see find()},
 *    {@see names()} - used to label old invoices and as the activation
 *    fallback for invoices that predate the duration snapshot.
 *
 * No caching on purpose: a price edited by a Super Admin must apply to the
 * very next checkout, and these are single indexed reads.
 */
class SubscriptionPlans
{
    /**
     * Any plan, active or not.
     *
     * @return array{name: string, amount: int, duration_days: int}|null
     */
    public static function find(string $code): ?array
    {
        $plan = SubscriptionPlan::query()->where('code', $code)->first();

        return $plan === null ? null : self::toArray($plan);
    }

    /**
     * An active plan only - the one a checkout may sell.
     *
     * @return array{name: string, amount: int, duration_days: int}|null
     */
    public static function findSellable(string $code): ?array
    {
        $plan = SubscriptionPlan::query()->active()->where('code', $code)->first();

        return $plan === null ? null : self::toArray($plan);
    }

    /**
     * Active plans, in display order, keyed by code.
     *
     * @return array<string, array{name: string, amount: int, duration_days: int}>
     */
    public static function sellable(): array
    {
        return SubscriptionPlan::query()->active()->ordered()->get()
            ->mapWithKeys(fn (SubscriptionPlan $plan) => [$plan->code => self::toArray($plan)])
            ->all();
    }

    /**
     * Codes of the plans that may be bought.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::sellable());
    }

    /**
     * Display name of every plan (inactive included), keyed by code.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        return SubscriptionPlan::query()->ordered()->pluck('name', 'code')->all();
    }

    /**
     * @return array{name: string, amount: int, duration_days: int}
     */
    private static function toArray(SubscriptionPlan $plan): array
    {
        return [
            'name' => $plan->name,
            'amount' => $plan->amount,
            'duration_days' => $plan->duration_days,
        ];
    }
}
