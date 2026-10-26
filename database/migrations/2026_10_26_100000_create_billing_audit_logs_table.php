<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only trail of what a Super Admin did to billing data by hand
 * (checking an invoice's status at DOKU, forcing a sync). Same shape of
 * discipline as organization_support_actions: no updated_at, and no code
 * path edits or deletes a row except the scheduled retention prune
 * (App\Models\BillingAuditLog::prunable()).
 *
 * Every foreign key is nullOnDelete, not cascade: an audit row must outlive
 * the user who acted, the invoice, and the whole organization (deleting an
 * organization cascades its invoices away) - that is precisely when
 * someone later asks "who touched that?". invoice_number is therefore
 * copied onto the row as a snapshot, so a row whose invoice_id has been
 * nulled still says which invoice it was about.
 *
 * What is deliberately NOT stored: any DOKU secret, signature, token,
 * payment URL, request id, raw request/response payload, exception
 * message, or the actor's IP address. The row records WHO did WHAT to
 * WHICH invoice with WHAT RESULT and WHY - nothing that could leak a
 * credential if the table were ever exported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->string('invoice_number')->nullable();

            $table->string('action');
            $table->string('result');
            $table->text('reason')->nullable();

            // Local invoice status before/after the action, not DOKU's.
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['invoice_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_audit_logs');
    }
};
