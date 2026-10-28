<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The plan catalog as data a Super Admin can edit, instead of a config file
 * only a deploy can change.
 *
 * `code` is what invoices.plan_code and the checkout form refer to, so it is
 * written once and never changed. A plan is never deleted either - only
 * deactivated (is_active = false): old invoices still point at its code, and
 * the activation fallback for pre-snapshot invoices still has to find it. An
 * inactive plan cannot be bought (see App\Support\SubscriptionPlans).
 *
 * `amount` is whole IDR (DOKU's order.amount is an integer), exactly like
 * invoices.amount, so there is no decimal rounding anywhere on the money path.
 *
 * Existing behaviour is preserved by seeding the table from
 * config('subscriptions.plans') when (and only when) it is empty: the same
 * codes, names, prices and durations, in config order (sort_order 1, 2, ...), all active. After that
 * the config array is no longer read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('duration_days');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        if (DB::table('subscription_plans')->exists()) {
            return;
        }

        $order = 0;

        foreach ((array) config('subscriptions.plans', []) as $code => $plan) {
            DB::table('subscription_plans')->insert([
                'code' => (string) $code,
                'name' => (string) $plan['name'],
                'amount' => (int) $plan['amount'],
                'duration_days' => (int) $plan['duration_days'],
                'is_active' => true,
                'sort_order' => (int) ($plan['sort_order'] ?? ++$order),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
