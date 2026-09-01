<?php

namespace Tests\Feature\Console;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupOldActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
    }

    private function activityAgedDays(int $days): TicketActivity
    {
        $activity = TicketActivity::create([
            'ticket_id' => Ticket::factory()->create()->id,
            'old_status_id' => TicketStatus::factory()->create()->id,
            'new_status_id' => TicketStatus::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);

        // Age the record past the archive threshold.
        $activity->forceFill(['created_at' => now()->subDays($days)])->save();

        return $activity;
    }

    public function test_it_archives_and_removes_activities_older_than_the_threshold(): void
    {
        $old = $this->activityAgedDays(120);
        $recent = $this->activityAgedDays(10);

        $this->artisan('cleanup:old-activities', ['--days' => 90])
            ->assertSuccessful();

        // Old one gone from the DB, recent one kept.
        $this->assertDatabaseMissing('ticket_activities', ['id' => $old->id]);
        $this->assertDatabaseHas('ticket_activities', ['id' => $recent->id]);

        // The archive file exists and contains the removed record.
        $files = Storage::disk('local')->files('archives');
        $this->assertCount(1, $files);
        $this->assertStringContainsString((string) $old->id, Storage::disk('local')->get($files[0]));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $old = $this->activityAgedDays(120);

        $this->artisan('cleanup:old-activities', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertDatabaseHas('ticket_activities', ['id' => $old->id]);
        $this->assertEmpty(Storage::disk('local')->files('archives'));
    }

    public function test_it_reports_when_there_is_nothing_to_archive(): void
    {
        $this->activityAgedDays(5); // newer than threshold

        $this->artisan('cleanup:old-activities', ['--days' => 90])
            ->expectsOutputToContain('No activities older than')
            ->assertSuccessful();
    }

    public function test_it_rejects_an_invalid_days_option(): void
    {
        $this->artisan('cleanup:old-activities', ['--days' => 0])
            ->assertFailed();
    }
}
