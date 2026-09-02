<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Organization Foundation) — see
 * docs/adr/0001-hybrid-multi-tenant-authorization.md.
 *
 * Pivot table for Organization <-> User membership only. No organization
 * role column here: Organization-level RBAC is handled by Spatie Teams in a
 * later phase (ADR "Organization" section), not by this pivot.
 *
 * cascadeOnDelete on both sides mirrors this app's other pivot tables (e.g.
 * ticket_label): a membership row is meaningless once either side of it is
 * gone, so removing the organization or the user must not leave orphaned
 * pivot rows behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_users');
    }
};
