<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of what the customer was sold, taken at checkout.
 *
 * `amount` was already a snapshot, but the duration was looked up from
 * config('subscriptions.plans') only when the payment was read back. If the
 * plan's duration changed (or the plan was removed) between checkout and
 * payment, the customer received the wrong period, or an invoice became
 * `paid` with no Subscription at all. Freezing the duration and the plan's
 * display name on the invoice makes that window harmless, and is the
 * prerequisite for ever editing plans from a UI.
 *
 * Both columns are nullable on purpose: rows created before this migration
 * (and sandbox rows) have no snapshot. They are NOT backfilled - guessing a
 * duration from today's config would be exactly the bug being fixed - and
 * SubscriptionActivationService falls back to the config lookup for them,
 * which is the behaviour they always had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('duration_days')->nullable()->after('plan_code');
            $table->string('plan_name')->nullable()->after('duration_days');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['duration_days', 'plan_name']);
        });
    }
};
