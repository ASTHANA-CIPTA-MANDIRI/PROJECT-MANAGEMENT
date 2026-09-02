<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (Organization RBAC) — see docs/adr/0001-hybrid-multi-tenant-authorization.md.
 *
 * NOT the quarantined spike migration (2026_09_01_990000_..._poc.php, which
 * must never run outside the spike). Audited before writing this: enabling
 * spatie/laravel-permission's Teams feature would team-scope every existing
 * role/permission check in the app (HasRoles::roles() bakes team-scoping
 * into the relationship itself, not just hasRole()) — since every existing
 * model_has_roles row has team_id = NULL, that would silently lock every
 * user (including Super Admin) out of permissions they already hold the
 * moment they have any active Organization context. Teams stays off.
 *
 * Organization RBAC is instead a plain `role` column on `organization_users`,
 * mirroring `project_users.role` (2022_11_02_131753_create_project_users_table.php)
 * exactly — a pattern already proven safe in this codebase, with no Spatie
 * table/config/trait involved at all.
 *
 * A default (not a nullable column) so every already-backfilled Phase 2
 * membership row gets a valid, immediately-consistent value the moment this
 * migration runs — no separate backfill step, no row left in a state the
 * application doesn't understand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_users', function (Blueprint $table) {
            $table->string('role')->default('member')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
