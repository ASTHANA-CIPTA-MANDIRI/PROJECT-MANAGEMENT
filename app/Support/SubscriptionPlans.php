<?php

namespace App\Support;

/**
 * Fase 8-9 - the single place that resolves a plan *code* (client-supplied,
 * e.g. a Livewire/form select value) into its server-authoritative price
 * and duration from config('subscriptions.plans'). Mirrors the rest of the
 * codebase's "a client-supplied id is never trusted past the UI layer"
 * rule (CLAUDE.md): App\Http\Controllers\SubscriptionCheckoutController
 * never reads an amount from the request, only a plan code, which is
 * re-resolved through here immediately before use.
 */
class SubscriptionPlans
{
    /**
     * @return array{name: string, amount: int, duration_days: int}|null
     */
    public static function find(string $code): ?array
    {
        $plan = config("subscriptions.plans.{$code}");

        return is_array($plan) ? $plan : null;
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(config('subscriptions.plans', []));
    }
}
