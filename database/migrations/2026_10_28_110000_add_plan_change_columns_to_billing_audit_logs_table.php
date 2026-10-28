<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets billing_audit_logs also record changes to a subscription plan, not
 * only manual actions on an invoice.
 *
 * old_status / new_status are short strings holding an invoice status, too
 * small and too specific to say "amount 150000 -> 200000, duration 30 -> 45".
 * So a plan change stores what it was about in `subject` (e.g. "plan:monthly")
 * and the before/after values in old_value / new_value (a compact JSON of the
 * plan's editable fields). All three are nullable: every existing row (an
 * invoice check/sync) leaves them null, and a plan row leaves invoice_id,
 * organization_id and the status columns null.
 *
 * Same rule as the table itself: nothing sensitive goes in. A plan's name,
 * price, duration, active flag and order are not secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_audit_logs', function (Blueprint $table) {
            $table->string('subject', 64)->nullable()->after('invoice_number');
            $table->text('old_value')->nullable()->after('new_status');
            $table->text('new_value')->nullable()->after('old_value');

            $table->index(['subject', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['subject', 'created_at']);
            $table->dropColumn(['subject', 'old_value', 'new_value']);
        });
    }
};
