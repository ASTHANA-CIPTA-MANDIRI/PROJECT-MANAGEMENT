<?php

namespace Tests\Feature\Billing;

use App\Filament\Pages\PlatformOrganizations;
use App\Models\BillingAuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationTrialManager;
use App\Services\TrialChangeException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A Super Admin changing organizations.trial_ends_at (the column that locks an
 * organization out) leaves exactly one billing_audit_logs row, written in the
 * same transaction as the change. The automatic 7-day trial of a registration
 * is the system acting without an actor and is deliberately not audited.
 */
class OrganizationTrialAuditTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::create(['name' => 'Super Admin'])]);

        return $user->fresh();
    }

    private function edit(Organization $organization, array $data)
    {
        return Livewire::test(PlatformOrganizations::class)
            ->callTableAction('editOrganization', $organization, data: ['name' => $organization->name] + $data);
    }

    private function trialLogs()
    {
        return BillingAuditLog::where('action', BillingAuditLog::ACTION_ORGANIZATION_TRIAL_UPDATE);
    }

    public function test_extending_a_trial_writes_one_audit_row_with_actor_organization_old_and_new_value_and_reason(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $organization = Organization::factory()->create(['trial_ends_at' => '2026-11-01 00:00:00']);

        $this->edit($organization, ['trial_ends_at' => '2026-11-15', 'reason' => '  Menunggu transfer  '])
            ->assertHasNoTableActionErrors();

        $this->assertSame('2026-11-15', $organization->fresh()->trial_ends_at->toDateString());

        $log = $this->trialLogs()->sole();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame('organization:'.$organization->id, $log->subject);
        $this->assertSame('success', $log->result);
        $this->assertSame('2026-11-01T00:00:00Z', $log->old_value);
        $this->assertSame('2026-11-15T00:00:00Z', $log->new_value);
        $this->assertSame('Menunggu transfer', $log->reason);
        $this->assertNull($log->invoice_id);
        $this->assertSame(365, BillingAuditLog::RETENTION_DAYS[$log->action]);
    }

    public function test_the_reason_is_optional_and_blank_is_stored_as_null(): void
    {
        $this->actingAs($this->superAdmin());
        $organization = Organization::factory()->create(['trial_ends_at' => '2026-11-01 00:00:00']);

        $this->edit($organization, ['trial_ends_at' => '2026-11-02', 'reason' => '   '])->assertHasNoTableActionErrors();

        $this->assertNull($this->trialLogs()->sole()->reason);
    }

    public function test_a_reason_over_500_characters_is_refused_and_nothing_changes(): void
    {
        $this->actingAs($this->superAdmin());
        $organization = Organization::factory()->create(['trial_ends_at' => '2026-11-01 00:00:00']);

        $this->edit($organization, ['trial_ends_at' => '2026-11-02', 'reason' => str_repeat('a', 501)])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame('2026-11-01', $organization->fresh()->trial_ends_at->toDateString());
        $this->assertSame(0, $this->trialLogs()->count());
    }

    public function test_clearing_the_trial_is_audited_as_unlimited_and_setting_one_from_unlimited_too(): void
    {
        $this->actingAs($this->superAdmin());
        $organization = Organization::factory()->create(['trial_ends_at' => '2026-11-01 00:00:00']);

        $this->edit($organization, ['trial_ends_at' => null]);
        $this->edit($organization->fresh(), ['trial_ends_at' => '2026-12-01']);

        $logs = $this->trialLogs()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['2026-11-01T00:00:00Z', 'unlimited'], [$logs[0]->old_value, $logs[0]->new_value]);
        $this->assertSame(['unlimited', '2026-12-01T00:00:00Z'], [$logs[1]->old_value, $logs[1]->new_value]);
    }

    public function test_nothing_is_audited_when_the_trial_is_not_changed_even_if_the_name_is(): void
    {
        $this->actingAs($this->superAdmin());
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)->setTime(14, 30)]);
        $before = $organization->fresh()->trial_ends_at;
        $unlimited = Organization::factory()->create(['trial_ends_at' => null]);

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('editOrganization', $organization, data: [
                'name' => 'Renamed Co',
                'trial_ends_at' => $before->toDateString(),
            ])
            ->callTableAction('editOrganization', $unlimited, data: ['name' => 'Unlimited Co', 'trial_ends_at' => null]);

        $this->assertSame('Renamed Co', $organization->fresh()->name);
        $this->assertTrue($before->equalTo($organization->fresh()->trial_ends_at), 'a rename must not move the stored moment');
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_when_the_audit_row_cannot_be_written_the_trial_and_the_rename_are_not_saved(): void
    {
        $this->actingAs($this->superAdmin());
        $organization = Organization::factory()->create(['name' => 'Old Name', 'trial_ends_at' => '2026-11-01 00:00:00']);
        DB::statement('DROP TABLE billing_audit_logs');

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('editOrganization', $organization, data: ['name' => 'New Name', 'trial_ends_at' => '2026-12-31'])
            ->assertSuccessful();

        $organization->refresh();
        $this->assertSame('Old Name', $organization->name);
        $this->assertSame('2026-11-01', $organization->trial_ends_at->toDateString());
    }

    public function test_a_failed_audit_surfaces_a_safe_message_without_driver_details(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['trial_ends_at' => '2026-11-01 00:00:00']);
        DB::statement('DROP TABLE billing_audit_logs');

        try {
            app(OrganizationTrialManager::class)->change($admin, $organization, '2026-12-01');
            $this->fail('A failing audit write must abort the change.');
        } catch (TrialChangeException $e) {
            $this->assertStringNotContainsString('billing_audit_logs', $e->getMessage());
            $this->assertStringNotContainsString('SQL', $e->getMessage());
        }

        $this->assertSame('2026-11-01', $organization->fresh()->trial_ends_at->toDateString());
    }

    public function test_creating_an_organization_with_a_trial_audits_the_initial_value_in_the_same_transaction(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $owner = User::factory()->create();

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: ['name' => 'Born Co', 'owner_id' => $owner->id, 'trial_ends_at' => '2026-12-01'])
            ->assertHasNoTableActionErrors();

        $organization = Organization::where('name', 'Born Co')->firstOrFail();
        $log = $this->trialLogs()->sole();
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertNull($log->old_value);
        $this->assertSame('2026-12-01T00:00:00Z', $log->new_value);
    }

    public function test_creating_an_unlimited_organization_is_audited_as_unlimited(): void
    {
        $this->actingAs($this->superAdmin());
        $owner = User::factory()->create();

        Livewire::test(PlatformOrganizations::class)
            ->callTableAction('createOrganization', null, data: ['name' => 'Forever Co', 'owner_id' => $owner->id, 'trial_ends_at' => null]);

        $this->assertSame('unlimited', $this->trialLogs()->sole()->new_value);
    }

    public function test_when_the_creation_audit_cannot_be_written_no_organization_is_created(): void
    {
        $this->actingAs($this->superAdmin());
        $owner = User::factory()->create();
        DB::statement('DROP TABLE billing_audit_logs');

        try {
            Livewire::test(PlatformOrganizations::class)
                ->callTableAction('createOrganization', null, data: ['name' => 'Ghost Co', 'owner_id' => $owner->id, 'trial_ends_at' => '2026-12-01']);
        } catch (\Throwable) {
            // fail-closed: the exception may surface, what matters is below
        }

        $this->assertSame(0, Organization::where('name', 'Ghost Co')->count());
    }

    public function test_a_non_super_admin_is_refused_and_nothing_is_audited(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create(['trial_ends_at' => '2026-11-01 00:00:00']);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);

        Livewire::test(PlatformOrganizations::class)->assertForbidden();

        try {
            app(OrganizationTrialManager::class)->change($user, $organization, '2027-01-01');
            $this->fail('Must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('2026-11-01', $organization->fresh()->trial_ends_at->toDateString());
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_the_automatic_registration_trial_is_not_audited(): void
    {
        $user = User::factory()->create();

        event(new Registered($user));

        $this->assertNotNull($user->fresh()->organizations()->sole()->trial_ends_at);
        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_the_backfill_command_does_not_audit(): void
    {
        User::factory()->create();

        $this->artisan('organizations:backfill')->assertSuccessful();

        $this->assertSame(0, BillingAuditLog::count());
    }

    public function test_the_audit_row_holds_no_sensitive_data(): void
    {
        $this->actingAs($this->superAdmin());
        $organization = Organization::factory()->create(['trial_ends_at' => '2026-11-01 00:00:00']);

        $this->edit($organization, ['trial_ends_at' => '2026-11-02']);

        $attributes = $this->trialLogs()->sole()->getAttributes();
        $this->assertEqualsCanonicalizing(
            ['id', 'actor_user_id', 'invoice_id', 'organization_id', 'invoice_number', 'subject', 'action', 'result', 'reason', 'old_status', 'new_status', 'old_value', 'new_value', 'created_at'],
            array_keys($attributes)
        );
        $this->assertNull($attributes['invoice_number']);
        $this->assertNull($attributes['old_status']);
        $this->assertNull($attributes['new_status']);
    }

    public function test_trial_audit_rows_are_kept_365_days_then_pruned_and_other_actions_are_unaffected(): void
    {
        $make = fn (string $action, int $days) => BillingAuditLog::factory()
            ->state(['action' => $action])
            ->create(['created_at' => now()->subDays($days)]);

        $trial = BillingAuditLog::ACTION_ORGANIZATION_TRIAL_UPDATE;
        $old = $make($trial, 366);
        $keptTrial = $make($trial, 364);
        $planAt100d = $make(BillingAuditLog::ACTION_PLAN_UPDATE, 100); // plan changes: 90 days -> pruned
        $syncAt300d = $make(BillingAuditLog::ACTION_SYNC, 300);

        $this->artisan('model:prune', ['--model' => BillingAuditLog::class])->assertSuccessful();

        $this->assertNull(BillingAuditLog::find($old->id));
        $this->assertNull(BillingAuditLog::find($planAt100d->id));
        $this->assertNotNull(BillingAuditLog::find($keptTrial->id));
        $this->assertNotNull(BillingAuditLog::find($syncAt300d->id));
    }

    public function test_the_organization_history_lists_the_trial_change_with_who_and_old_to_new(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $organization = Organization::factory()->create(['trial_ends_at' => '2026-11-01 00:00:00']);

        $this->edit($organization, ['trial_ends_at' => '2026-11-15']);

        Livewire::test(PlatformOrganizations::class)
            ->call('selectOrganization', $organization->id)
            ->assertSee(__('Trial period changed'))
            ->assertSee($admin->name);
    }
}
