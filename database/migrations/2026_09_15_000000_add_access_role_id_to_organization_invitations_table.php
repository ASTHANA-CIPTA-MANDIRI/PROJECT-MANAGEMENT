<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets whoever invites a member also pick which Spatie Role (feature
 * permissions - the same "Roles" panel only Super Admin manages) the
 * recipient gets, instead of every invitee always landing on the single
 * platform-wide default role. Deliberately nullable and nullOnDelete: an
 * invitation with no access role picked (or whose picked Role was since
 * deleted) still works exactly as before this column existed - it just
 * has nothing extra to apply at acceptance, never a hard failure.
 *
 * Explicitly NOT a path to grant the Super Admin role through an
 * invitation - see App\Filament\Pages\OrganizationSettings's addMember
 * action, which excludes it from the picker AND re-validates server-side
 * that the submitted role id isn't it, the same "never trust the
 * rendered <select>'s options alone" discipline every other role-picking
 * action in this app already follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->foreignId('access_role_id')
                ->nullable()
                ->after('role')
                ->constrained('roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_role_id');
        });
    }
};
