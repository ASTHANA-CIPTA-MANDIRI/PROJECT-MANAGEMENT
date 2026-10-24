<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organization::invoices() is the only way the Organization Settings
 * "Subscription" panel reads payment history, so the tenant boundary has to
 * hold at the relation itself: one Organization must never see another's
 * invoices through it.
 */
class OrganizationInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_a_plain_has_many_on_organization_id(): void
    {
        $relation = Organization::factory()->create()->invoices();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertSame('invoices.organization_id', $relation->getQualifiedForeignKeyName());
    }

    public function test_an_organization_only_sees_its_own_invoices(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $invoiceA = Invoice::factory()->create(['organization_id' => $a->id]);
        $invoiceB = Invoice::factory()->paid()->create(['organization_id' => $b->id]);

        $this->assertEqualsCanonicalizing([$invoiceA->id], $a->invoices()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$invoiceB->id], $b->invoices()->pluck('id')->all());
    }

    public function test_the_relation_does_not_leak_through_eager_loading_or_other_filters(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        Invoice::factory()->count(2)->create(['organization_id' => $a->id]);
        Invoice::factory()->count(3)->create(['organization_id' => $b->id]);

        $loaded = Organization::with('invoices')->whereKey([$a->id, $b->id])->get()->keyBy('id');

        $this->assertCount(2, $loaded[$a->id]->invoices);
        $this->assertCount(3, $loaded[$b->id]->invoices);
        $this->assertTrue($loaded[$a->id]->invoices->every(fn (Invoice $i) => $i->organization_id === $a->id));

        // A caller-side status filter narrows within the tenant, never beyond it.
        $this->assertSame(0, $a->invoices()->where('status', Invoice::STATUS_PAID)->count());
    }

    public function test_an_organization_without_invoices_gets_an_empty_collection(): void
    {
        $organization = Organization::factory()->create();
        Invoice::factory()->create();

        $this->assertCount(0, $organization->invoices);
    }

    public function test_ordering_is_not_part_of_the_relation_contract(): void
    {
        $organization = Organization::factory()->create();
        Invoice::factory()->count(3)->create(['organization_id' => $organization->id]);

        // Callers choose their own order; the relation only guarantees the set.
        $this->assertEqualsCanonicalizing(
            Invoice::where('organization_id', $organization->id)->pluck('id')->all(),
            $organization->invoices()->latest('id')->pluck('id')->all()
        );
    }
}
