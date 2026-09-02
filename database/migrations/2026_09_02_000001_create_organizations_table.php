<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Organization Foundation) of the hybrid multi-tenant authorization
 * rollout — see docs/adr/0001-hybrid-multi-tenant-authorization.md.
 *
 * This table only stores the minimal data foundation. Spatie Teams RBAC,
 * subscription/plan, billing, and organization context enforcement are all
 * later phases and are intentionally not part of this migration.
 *
 * `name` is unique so the backfill command (organizations:backfill) can
 * safely re-run without ever creating a duplicate "Default Organization".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
