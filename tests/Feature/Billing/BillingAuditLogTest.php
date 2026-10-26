<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * billing_audit_logs as a table/model: foreign keys that outlive their
 * targets, per-action retention, append-only shape. What InvoiceSyncer
 * writes into it is covered in InvoiceSyncerAuditTest.
 */
class BillingAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_survive_deleting_the_user_the_invoice_and_the_organization(): void
    {
        $log = BillingAuditLog::factory()->create();
        $number = $log->invoice_number;

        $log->actor->forceDelete();
        $this->assertNull($log->fresh()->actor_user_id);

        // Deleting the organization cascades the invoice away.
        $log->invoice->organization->forceDelete();

        $log = $log->fresh();
        $this->assertNull($log->invoice_id);
        $this->assertNull($log->organization_id);
        $this->assertSame($number, $log->invoice_number);
    }

    public function test_invoice_deleted_on_its_own_keeps_the_row(): void
    {
        $invoice = Invoice::factory()->for(Organization::factory()->create())->create();
        $log = BillingAuditLog::factory()->create(['invoice_id' => $invoice->id]);

        $invoice->delete();

        $log->refresh();
        $this->assertNull($log->invoice_id);
        $this->assertSame($invoice->invoice_number, $log->invoice_number);
    }

    public function test_prunable_removes_old_syncs_and_checks_by_their_own_retention(): void
    {
        $old = fn (string $action, int $days) => BillingAuditLog::factory()
            ->state(['action' => $action])
            ->create(['created_at' => now()->subDays($days)->subMinute()]);
        $recent = fn (string $action, int $days) => BillingAuditLog::factory()
            ->state(['action' => $action])
            ->create(['created_at' => now()->subDays($days)->addHour()]);

        $oldSync = $old(BillingAuditLog::ACTION_SYNC, 365);
        $oldCheck = $old(BillingAuditLog::ACTION_CHECK_STATUS, 90);
        $syncAt100d = $recent(BillingAuditLog::ACTION_SYNC, 100); // older than a check's retention, but a sync
        $recentSync = $recent(BillingAuditLog::ACTION_SYNC, 365);
        $recentCheck = $recent(BillingAuditLog::ACTION_CHECK_STATUS, 90);
        $unknownOldAction = $old('invoice.something_new', 400);

        $this->artisan('model:prune', ['--model' => BillingAuditLog::class])->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$syncAt100d->id, $recentSync->id, $recentCheck->id, $unknownOldAction->id],
            BillingAuditLog::pluck('id')->all()
        );
        $this->assertNull(BillingAuditLog::find($oldSync->id));
        $this->assertNull(BillingAuditLog::find($oldCheck->id));
    }

    public function test_model_prune_discovers_the_audit_log(): void
    {
        BillingAuditLog::factory()->sync()->create(['created_at' => now()->subDays(366)]);

        $this->artisan('model:prune', ['--pretend' => true])
            ->expectsOutputToContain(BillingAuditLog::class)
            ->assertSuccessful();
    }

    public function test_the_log_is_append_only_by_construction(): void
    {
        $log = BillingAuditLog::factory()->create();

        $this->assertNull(BillingAuditLog::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }

    public function test_the_table_has_only_the_audited_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'actor_user_id', 'invoice_id', 'organization_id', 'invoice_number', 'action', 'result', 'reason', 'old_status', 'new_status', 'created_at'],
            Schema::getColumnListing('billing_audit_logs')
        );
    }
}
