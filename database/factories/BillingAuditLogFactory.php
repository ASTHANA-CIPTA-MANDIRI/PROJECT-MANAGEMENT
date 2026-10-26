<?php

namespace Database\Factories;

use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingAuditLog>
 */
class BillingAuditLogFactory extends Factory
{
    protected $model = BillingAuditLog::class;

    /**
     * Base state: a status check on a fresh invoice. invoice_number is
     * copied from the invoice, as BillingAuditLog::record() does.
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'invoice_id' => fn () => Invoice::factory()->create()->id,
            'organization_id' => fn (array $attrs) => Invoice::find($attrs['invoice_id'])->organization_id,
            'invoice_number' => fn (array $attrs) => Invoice::find($attrs['invoice_id'])->invoice_number,
            'action' => BillingAuditLog::ACTION_CHECK_STATUS,
            'result' => 'PENDING',
            'reason' => null,
            'old_status' => Invoice::STATUS_PENDING,
            'new_status' => Invoice::STATUS_PENDING,
        ];
    }

    public function sync(string $result = 'paid', string $reason = 'Pelanggan mengirim bukti transfer'): static
    {
        return $this->state(fn () => [
            'action' => BillingAuditLog::ACTION_SYNC,
            'result' => $result,
            'reason' => $reason,
        ]);
    }
}
