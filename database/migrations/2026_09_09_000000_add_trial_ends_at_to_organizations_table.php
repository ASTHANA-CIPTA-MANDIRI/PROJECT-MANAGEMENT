<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 (Trial per-Organization) — see docs/adr/0001-hybrid-multi-tenant-authorization.md
 * and the subscription-model-direction decision (2026-09-09): the trial
 * clock belongs to the Organization, not to each individual user, so an
 * Agent invited on day 6 rides the Owner's existing clock instead of
 * getting a fresh 7 days of their own.
 *
 * Nullable on purpose, same discipline as every other Organization column
 * added so far: every Organization that exists before this migration runs
 * (including the "Default Organization" produced by organizations:backfill)
 * keeps trial_ends_at = NULL, which App\Support\TrialGate treats as
 * grandfathered/unlimited — nothing that already works stops working the
 * moment this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
