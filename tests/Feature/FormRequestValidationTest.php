<?php

namespace Tests\Feature;

use App\Http\Requests\ProjectRequest;
use App\Http\Requests\SprintRequest;
use App\Http\Requests\TicketRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class FormRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;
    private int $projectStatusId;
    private int $projectId;
    private int $ticketStatusId;
    private int $ticketTypeId;
    private int $ticketPriorityId;

    /**
     * Seed the minimal related records the `exists` rules depend on.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $now = now();

        $this->userId = DB::table('users')->insertGetId([
            'name' => 'Tester', 'email' => 'tester@example.com',
            'password' => bcrypt('secret'), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->projectStatusId = DB::table('project_statuses')->insertGetId([
            'name' => 'Open', 'color' => '#fff', 'is_default' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->projectId = DB::table('projects')->insertGetId([
            'name' => 'Demo', 'owner_id' => $this->userId, 'status_id' => $this->projectStatusId,
            'ticket_prefix' => 'DEM', 'status_type' => 'default', 'type' => 'kanban',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->ticketStatusId = DB::table('ticket_statuses')->insertGetId([
            'name' => 'To do', 'color' => '#fff', 'is_default' => true, 'order' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->ticketTypeId = DB::table('ticket_types')->insertGetId([
            'name' => 'Bug', 'color' => '#fff', 'icon' => 'bug', 'is_default' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->ticketPriorityId = DB::table('ticket_priorities')->insertGetId([
            'name' => 'High', 'color' => '#fff', 'is_default' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function validate(array $rules, array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, $rules);
    }

    // ---------------------------------------------------------------- Project

    public function test_project_valid_payload_passes(): void
    {
        $v = $this->validate((new ProjectRequest())->rules(), [
            'name' => 'My project',
            'owner_id' => $this->userId,
            'status_id' => $this->projectStatusId,
            'ticket_prefix' => 'ABC',
            'type' => 'kanban',
            'status_type' => 'default',
        ]);

        $this->assertTrue($v->passes(), 'Valid project payload should pass. Errors: ' . $v->errors());
    }

    public function test_project_missing_required_fields_fail(): void
    {
        $v = $this->validate((new ProjectRequest())->rules(), []);

        $this->assertFalse($v->passes());
        foreach (['name', 'owner_id', 'status_id', 'ticket_prefix', 'type', 'status_type'] as $field) {
            $this->assertArrayHasKey($field, $v->errors()->toArray(), "$field should be required.");
        }
    }

    public function test_project_rejects_invalid_type_and_long_prefix(): void
    {
        $v = $this->validate((new ProjectRequest())->rules(), [
            'name' => 'X', 'owner_id' => $this->userId, 'status_id' => $this->projectStatusId,
            'ticket_prefix' => 'TOOLONG', 'type' => 'waterfall', 'status_type' => 'default',
        ]);

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('type', $v->errors()->toArray());
        $this->assertArrayHasKey('ticket_prefix', $v->errors()->toArray());
    }

    public function test_project_rejects_nonexistent_owner(): void
    {
        $v = $this->validate((new ProjectRequest())->rules(), [
            'name' => 'X', 'owner_id' => 99999, 'status_id' => $this->projectStatusId,
            'ticket_prefix' => 'ABC', 'type' => 'kanban', 'status_type' => 'default',
        ]);

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('owner_id', $v->errors()->toArray());
    }

    // ----------------------------------------------------------------- Ticket

    public function test_ticket_valid_payload_passes(): void
    {
        $v = $this->validate((new TicketRequest())->rules(), [
            'name' => 'Fix bug', 'content' => 'Steps to reproduce',
            'project_id' => $this->projectId, 'owner_id' => $this->userId,
            'status_id' => $this->ticketStatusId, 'type_id' => $this->ticketTypeId,
            'priority_id' => $this->ticketPriorityId, 'estimation' => 3.5,
        ]);

        $this->assertTrue($v->passes(), 'Valid ticket payload should pass. Errors: ' . $v->errors());
    }

    public function test_ticket_rejects_negative_estimation(): void
    {
        $v = $this->validate((new TicketRequest())->rules(), [
            'name' => 'X', 'content' => 'Y', 'project_id' => $this->projectId,
            'owner_id' => $this->userId, 'status_id' => $this->ticketStatusId,
            'type_id' => $this->ticketTypeId, 'priority_id' => $this->ticketPriorityId,
            'estimation' => -5,
        ]);

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('estimation', $v->errors()->toArray());
    }

    public function test_ticket_missing_required_fields_fail(): void
    {
        $v = $this->validate((new TicketRequest())->rules(), []);

        $this->assertFalse($v->passes());
        foreach (['name', 'content', 'project_id', 'owner_id', 'status_id', 'type_id', 'priority_id'] as $field) {
            $this->assertArrayHasKey($field, $v->errors()->toArray(), "$field should be required.");
        }
    }

    // ----------------------------------------------------------------- Sprint

    public function test_sprint_valid_payload_passes(): void
    {
        $v = $this->validate((new SprintRequest())->rules(), [
            'name' => 'Sprint 1', 'starts_at' => '2026-01-01', 'ends_at' => '2026-01-14',
            'project_id' => $this->projectId,
        ]);

        $this->assertTrue($v->passes(), 'Valid sprint payload should pass. Errors: ' . $v->errors());
    }

    public function test_sprint_rejects_end_before_start(): void
    {
        $v = $this->validate((new SprintRequest())->rules(), [
            'name' => 'Sprint 1', 'starts_at' => '2026-01-14', 'ends_at' => '2026-01-01',
            'project_id' => $this->projectId,
        ]);

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('ends_at', $v->errors()->toArray());
    }

    public function test_sprint_missing_required_fields_fail(): void
    {
        $v = $this->validate((new SprintRequest())->rules(), []);

        $this->assertFalse($v->passes());
        foreach (['name', 'starts_at', 'ends_at', 'project_id'] as $field) {
            $this->assertArrayHasKey($field, $v->errors()->toArray(), "$field should be required.");
        }
    }
}
