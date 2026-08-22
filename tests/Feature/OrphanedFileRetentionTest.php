<?php

namespace Tests\Feature;

use App\Models\AttachmentLink;
use App\Models\DataJob;
use App\Models\FileObject;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\RequirementBook;
use App\Models\RequirementBookVersion;
use App\Models\TimelineEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class OrphanedFileRetentionTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('project-desk.uploads.disk', 'local');
        config()->set('project-desk.uploads.orphan_retention_hours', 72);
    }

    public function test_command_prunes_only_old_unreferenced_files_and_audits_the_deletion(): void
    {
        $admin = $this->makeUser('admin');
        $old = $this->file($admin->id, 'orphan-old.pdf', now()->subHours(73));
        $fresh = $this->file($admin->id, 'orphan-fresh.pdf', now()->subHours(71));

        $this->artisan('project-desk:prune-orphaned-files')->assertSuccessful();

        $this->assertDatabaseMissing('file_objects', ['id' => $old->id]);
        $this->assertDatabaseHas('file_objects', ['id' => $fresh->id]);
        Storage::disk('local')->assertMissing($old->storage_key);
        Storage::disk('local')->assertExists($fresh->storage_key);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.retention_pruned',
            'subject_id' => $old->id,
            'actor_id' => null,
        ]);
    }

    public function test_dry_run_reports_without_changing_database_or_storage(): void
    {
        $admin = $this->makeUser('admin');
        $orphan = $this->file($admin->id, 'dry-run.pdf', now()->subWeek());

        $this->artisan('project-desk:prune-orphaned-files', ['--dry-run' => true])
            ->expectsOutputToContain('eligible=1')
            ->assertSuccessful();

        $this->assertDatabaseHas('file_objects', ['id' => $orphan->id]);
        Storage::disk('local')->assertExists($orphan->storage_key);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'project_file.retention_pruned',
            'subject_id' => $orphan->id,
        ]);
    }

    public function test_all_durable_reference_types_protect_old_files_including_archived_links(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $files = collect(['link', 'archived-link', 'book', 'minutes', 'job'])
            ->mapWithKeys(fn (string $name): array => [
                $name => $this->file($admin->id, $name.'.pdf', now()->subWeek()),
            ]);
        AttachmentLink::query()->create([
            'file_object_id' => $files['link']->id,
            'project_id' => $project->id,
        ]);
        AttachmentLink::query()->create([
            'file_object_id' => $files['archived-link']->id,
            'project_id' => $project->id,
            'archived_at' => now()->subDay(),
        ]);
        $book = RequirementBook::query()->create([
            'project_id' => $project->id,
            'title' => 'Requirement book',
        ]);
        RequirementBookVersion::query()->create([
            'requirement_book_id' => $book->id,
            'title' => 'Version one',
            'version_number' => 1,
            'status' => 'approved',
            'file_object_id' => $files['book']->id,
            'uploaded_by' => $admin->id,
            'uploaded_at' => now()->subWeek(),
            'is_current' => true,
        ]);
        $timeline = TimelineEntry::query()->create([
            'project_id' => $project->id,
            'kind' => 'meeting',
            'title' => 'Minutes retention',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
            'status' => 'done',
            'owner_id' => $admin->id,
        ]);
        $meeting = Meeting::query()->create([
            'timeline_entry_id' => $timeline->id,
            'organizer_id' => $admin->id,
        ]);
        MeetingMinutes::query()->create([
            'meeting_id' => $meeting->id,
            'summary' => 'Minutes',
            'file_object_id' => $files['minutes']->id,
            'recorded_by' => $admin->id,
            'recorded_at' => now()->subWeek(),
        ]);
        DataJob::query()->create([
            'type' => 'backup',
            'resource_type' => 'system',
            'format' => 'pdesk',
            'status' => 'succeeded',
            'file_object_id' => $files['job']->id,
            'created_by' => $admin->id,
        ]);

        $this->artisan('project-desk:prune-orphaned-files')->assertSuccessful();

        foreach ($files as $file) {
            $this->assertDatabaseHas('file_objects', ['id' => $file->id]);
            Storage::disk('local')->assertExists($file->storage_key);
        }
        $this->assertDatabaseMissing('activity_logs', ['action' => 'project_file.retention_pruned']);
    }

    public function test_invalid_retention_configuration_fails_closed(): void
    {
        $admin = $this->makeUser('admin');
        $orphan = $this->file($admin->id, 'invalid-config.pdf', now()->subWeek());
        config()->set('project-desk.uploads.orphan_retention_hours', 0);

        $this->artisan('project-desk:prune-orphaned-files')->assertFailed();

        $this->assertDatabaseHas('file_objects', ['id' => $orphan->id]);
        Storage::disk('local')->assertExists($orphan->storage_key);
    }

    public function test_interrupted_quarantine_is_restored_when_the_file_record_still_exists(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $file = $this->file($admin->id, 'recover.pdf', now()->subWeek());
        AttachmentLink::query()->create([
            'file_object_id' => $file->id,
            'project_id' => $project->id,
        ]);
        $trash = '.project-desk-retention-trash/202608120330/'
            .$file->id.'-00000000-0000-4000-8000-000000000000.blob';
        $this->assertTrue(Storage::disk('local')->move($file->storage_key, $trash));

        $this->artisan('project-desk:prune-orphaned-files')->assertSuccessful();

        $this->assertDatabaseHas('file_objects', ['id' => $file->id]);
        Storage::disk('local')->assertExists($file->storage_key);
        Storage::disk('local')->assertMissing($trash);
    }

    public function test_command_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) $event->command,
                'project-desk:prune-orphaned-files',
            ));

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertSame('Africa/Tripoli', $event->timezone);
    }

    private function file(int $uploaderId, string $name, \DateTimeInterface $uploadedAt): FileObject
    {
        $key = 'projects/orphans/'.$name;
        $contents = "%PDF-1.7\n{$name}\n%%EOF";
        Storage::disk('local')->put($key, $contents);

        return FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => $key,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'scan_status' => 'safe',
            'uploaded_by' => $uploaderId,
            'uploaded_at' => $uploadedAt,
        ]);
    }
}
