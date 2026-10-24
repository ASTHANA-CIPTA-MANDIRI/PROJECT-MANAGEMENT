<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

/**
 * Fase 9 - the local "pending -> expired by timeout" transition the DOKU
 * spec recommends instead of relying on a gateway-sent expiry status
 * (verified spec section B: only `SUCCESS`, and for card payments
 * `FAILED`/`VOIDED`/`REFUNDED`, are documented `transaction.status`
 * values - nothing resembling "expired" is). A pending Invoice whose
 * locally-tracked expires_at (set at checkout time from
 * payment.payment_due_date) has passed can never become a legitimate
 * SUCCESS notification any more (DOKU's own Checkout page itself expires
 * at the same due date), so it is safe to close out here without waiting
 * on DOKU at all - but only expire_grace_minutes (default 30) AFTER the due
 * date, the same window subscriptions:sync-pending-invoices keeps polling
 * for, so a payment made at the last minute whose notification was missed
 * can still be caught by polling before this closes the invoice (closing it
 * at the exact due date raced the poller and dropped late SUCCESS as
 * already_processed). It never touches a Subscription (expiry is exactly the
 * "does not grant access" branch), only the Invoice's own status.
 */
class ExpirePendingInvoices extends Command
{
    protected $signature = 'subscriptions:expire-invoices';

    protected $description = 'Mark pending invoices past their DOKU payment due date as expired';

    public function handle(): int
    {
        $count = Invoice::where('status', Invoice::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subMinutes((int) config('doku.expire_grace_minutes')))
            ->update(['status' => Invoice::STATUS_EXPIRED]);

        $this->info("{$count} pending invoice(s) marked as expired.");

        return self::SUCCESS;
    }
}
