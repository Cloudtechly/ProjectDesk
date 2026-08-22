<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\TimelineEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProjectDeskTestData;
use Tests\Support\RegistersGovernanceRoutes;
use Tests\TestCase;

class TimelineMeetingWorkflowTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase, RegistersGovernanceRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerGovernanceRoutes();
    }

    public function test_timeline_entries_support_crud_timezone_conversion_and_exclude_meeting_kind(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)->postJson(route('projects.timeline-entries.store', $project), [
            'kind' => 'milestone',
            'title' => 'اعتماد النسخة الأولى',
            'starts_at' => '2026-08-18 09:00:00',
            'ends_at' => null,
            'status' => 'planned',
            'owner_id' => $manager->id,
            'metadata' => ['color' => '#406386'],
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'milestone')
            ->assertJsonPath('data.lock_version', 1);

        $entry = TimelineEntry::query()->firstOrFail();
        $this->assertSame('2026-08-18 07:00:00', $entry->starts_at->format('Y-m-d H:i:s'));
        $this->assertNull($entry->ends_at);

        $this->actingAs($manager)->putJson(route('projects.timeline-entries.update', [$project, $entry]), [
            'kind' => 'delivery',
            'title' => 'تسليم النسخة الأولى',
            'starts_at' => '2026-08-19 10:00:00',
            'ends_at' => '2026-08-19 11:00:00',
            'status' => 'completed',
            'owner_id' => $manager->id,
            'note' => 'تم التسليم.',
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.lock_version', 2);

        $this->actingAs($manager)->putJson(route('projects.timeline-entries.update', [$project, $entry]), [
            'kind' => 'deadline',
            'title' => 'كتابة قديمة لا تحفظ',
            'starts_at' => '2026-08-20 10:00:00',
            'ends_at' => null,
            'status' => 'planned',
            'owner_id' => $manager->id,
            'note' => null,
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertDatabaseHas('timeline_entries', [
            'id' => $entry->id,
            'title' => 'تسليم النسخة الأولى',
            'status' => 'completed',
            'lock_version' => 2,
        ]);

        $this->actingAs($manager)->postJson(route('projects.timeline-entries.store', $project), [
            'kind' => 'meeting',
            'title' => 'يجب استعمال مورد الاجتماع',
            'starts_at' => '2026-08-19 10:00:00',
            'ends_at' => '2026-08-19 11:00:00',
            'status' => 'planned',
        ])->assertUnprocessable()->assertJsonValidationErrors('kind');

        $this->actingAs($manager)->postJson(route('projects.timeline-entries.archive', [$project, $entry]), [
            'lock_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.lock_version', 3);
        $this->assertDatabaseHas('timeline_entries', ['id' => $entry->id]);
        $this->assertNotNull($entry->fresh()->archived_at);
        $this->actingAs($manager)->getJson(route('projects.timeline-entries.index', $project))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($manager)->getJson(route('projects.timeline-entries.index', [$project, 'archived' => 1]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($manager)->postJson(route('projects.timeline-entries.restore', [$project, $entry]), [
            'lock_version' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->actingAs($manager)->postJson(route('projects.timeline-entries.restore', [$project, $entry]), [
            'lock_version' => 3,
        ])->assertOk()
            ->assertJsonPath('data.archived_at', null)
            ->assertJsonPath('data.lock_version', 4);
        $this->assertNull($entry->fresh()->archived_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'timeline_entry.archived', 'subject_id' => $entry->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'timeline_entry.restored', 'subject_id' => $entry->id]);
    }

    public function test_meeting_create_and_update_keep_one_timeline_source_and_attendee_state(): void
    {
        $manager = $this->makeUser('project_manager');
        $attendee = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($attendee, ['project_role' => 'member', 'status' => 'active']);

        $create = $this->actingAs($manager)->postJson(route('projects.meetings.store', $project), [
            'title' => 'اجتماع اعتماد التدفق',
            'starts_at' => '2026-08-20 10:00:00',
            'ends_at' => '2026-08-20 11:00:00',
            'status' => 'planned',
            'organizer_id' => $manager->id,
            'location' => 'قاعة الاجتماعات',
            'meeting_url' => 'https://meet.example.test/project-desk',
            'agenda' => 'مراجعة القرارات المفتوحة.',
            'attendees' => [
                ['user_id' => $manager->id, 'attendance_status' => 'accepted'],
                ['user_id' => $attendee->id, 'attendance_status' => 'invited'],
            ],
        ]);
        $create->assertCreated()
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.timeline_entry.kind', 'meeting')
            ->assertJsonPath('data.timeline_entry.lock_version', 1)
            ->assertJsonCount(2, 'data.attendees');

        $meeting = Meeting::query()->firstOrFail();
        $entryId = $meeting->timeline_entry_id;
        $this->assertDatabaseCount('timeline_entries', 1);
        $this->assertDatabaseHas('timeline_entries', [
            'id' => $entryId,
            'project_id' => $project->id,
            'kind' => 'meeting',
            'starts_at' => '2026-08-20 08:00:00',
        ]);
        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => $meeting->id,
            'user_id' => $attendee->id,
            'attendance_status' => 'invited',
        ]);
        $this->actingAs($manager)->getJson(route('projects.meetings.index', $project))
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($manager)->putJson(route('projects.meetings.update', [$project, $meeting]), [
            'title' => 'اجتماع اعتماد التدفق — منجز',
            'starts_at' => '2026-08-20 10:30:00',
            'ends_at' => '2026-08-20 11:30:00',
            'status' => 'completed',
            'organizer_id' => $manager->id,
            'location' => 'عن بعد',
            'meeting_url' => 'https://meet.example.test/project-desk',
            'attendees' => [
                ['user_id' => $attendee->id, 'attendance_status' => 'attended'],
            ],
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.timeline_entry.status', 'completed')
            ->assertJsonPath('data.timeline_entry.lock_version', 2);

        $this->actingAs($manager)->putJson(route('projects.meetings.update', [$project, $meeting]), [
            'title' => 'كتابة اجتماع قديمة لا تحفظ',
            'starts_at' => '2026-08-21 10:30:00',
            'ends_at' => '2026-08-21 11:30:00',
            'status' => 'planned',
            'organizer_id' => $manager->id,
            'location' => 'قاعة قديمة',
            'meeting_url' => 'https://meet.example.test/stale',
            'attendees' => [
                ['user_id' => $manager->id, 'attendance_status' => 'accepted'],
            ],
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');

        $meeting->refresh();
        $this->assertSame($entryId, $meeting->timeline_entry_id);
        $this->assertDatabaseCount('timeline_entries', 1);
        $this->assertDatabaseMissing('meeting_attendees', ['meeting_id' => $meeting->id, 'user_id' => $manager->id]);
        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => $meeting->id,
            'user_id' => $attendee->id,
            'attendance_status' => 'attended',
        ]);
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'location' => 'عن بعد',
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('timeline_entries', [
            'id' => $entryId,
            'title' => 'اجتماع اعتماد التدفق — منجز',
            'status' => 'completed',
            'lock_version' => 2,
        ]);
    }

    public function test_meeting_rejects_invalid_schedule_and_inactive_or_out_of_scope_attendees(): void
    {
        $manager = $this->makeUser('project_manager');
        $inactive = $this->makeUser('member', 'inactive');
        $outsider = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($inactive, ['project_role' => 'member', 'status' => 'active']);
        $base = [
            'title' => 'اجتماع غير صالح',
            'starts_at' => '2026-08-20 11:00:00',
            'ends_at' => '2026-08-20 10:00:00',
            'status' => 'planned',
            'organizer_id' => $manager->id,
        ];

        $this->actingAs($manager)->postJson(route('projects.meetings.store', $project), $base)
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->actingAs($manager)->postJson(route('projects.meetings.store', $project), [
            ...$base,
            'ends_at' => '2026-08-20 12:00:00',
            'attendees' => [['user_id' => $inactive->id, 'attendance_status' => 'invited']],
        ])->assertUnprocessable()->assertJsonValidationErrors('attendees.0.user_id');
        $this->actingAs($manager)->postJson(route('projects.meetings.store', $project), [
            ...$base,
            'ends_at' => '2026-08-20 12:00:00',
            'attendees' => [['user_id' => $outsider->id, 'attendance_status' => 'invited']],
        ])->assertUnprocessable()->assertJsonValidationErrors('attendees.0.user_id');
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('timeline_entries', 0);
    }

    public function test_minutes_upsert_keeps_one_record_controls_file_scope_and_is_audited(): void
    {
        $manager = $this->makeUser('project_manager');
        $other = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $meeting = $this->createMeeting($manager->id, $project->id);
        $ownedFileId = $this->insertSafeFile($manager->id, 'minutes-owned.pdf');
        $foreignFileId = $this->insertSafeFile($other->id, 'minutes-foreign.pdf');

        $this->actingAs($manager)->putJson(route('projects.meetings.minutes.upsert', [$project, $meeting]), [
            'summary' => 'ملخص الاجتماع الأول.',
            'decisions' => 'اعتماد التدفق.',
            'action_items' => 'تنفيذ الشاشة.',
            'file_object_id' => $foreignFileId,
        ])->assertUnprocessable()->assertJsonValidationErrors('file_object_id');

        $this->actingAs($manager)->putJson(route('projects.meetings.minutes.upsert', [$project, $meeting]), [
            'summary' => 'ملخص الاجتماع الأول.',
            'decisions' => 'اعتماد التدفق.',
            'action_items' => 'تنفيذ الشاشة.',
            'file_object_id' => $ownedFileId,
        ])->assertOk()
            ->assertJsonPath('data.summary', 'ملخص الاجتماع الأول.')
            ->assertJsonPath('data.lock_version', 1);

        $minutes = MeetingMinutes::query()->firstOrFail();
        $this->assertDatabaseHas('attachment_links', [
            'project_id' => $project->id,
            'meeting_minutes_id' => $minutes->id,
            'file_object_id' => $ownedFileId,
        ]);

        $this->actingAs($manager)->putJson(route('projects.meetings.minutes.upsert', [$project, $meeting]), [
            'summary' => 'ملخص مصحح.',
            'decisions' => null,
            'action_items' => 'تنفيذ الشاشة وتحديث التوثيق.',
            'file_object_id' => null,
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.summary', 'ملخص مصحح.')
            ->assertJsonPath('data.lock_version', 2);

        $this->actingAs($manager)->putJson(route('projects.meetings.minutes.upsert', [$project, $meeting]), [
            'summary' => 'كتابة محضر قديمة لا تحفظ.',
            'decisions' => 'قرار قديم.',
            'action_items' => null,
            'file_object_id' => null,
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');

        $this->assertDatabaseCount('meeting_minutes', 1);
        $this->assertDatabaseCount('attachment_links', 0);
        $this->assertDatabaseHas('meeting_minutes', [
            'id' => $minutes->id,
            'summary' => 'ملخص مصحح.',
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'meeting_minutes.created', 'subject_id' => $minutes->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'meeting_minutes.updated', 'subject_id' => $minutes->id]);
    }

    public function test_meeting_archive_preserves_timeline_minutes_and_history_and_can_be_restored(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $meeting = $this->createMeeting($manager->id, $project->id);
        $timeline = $meeting->timelineEntry;
        $fileId = $this->insertSafeFile($manager->id, 'archived-minutes.pdf');
        $minutes = MeetingMinutes::query()->create([
            'meeting_id' => $meeting->id,
            'summary' => 'محضر سيبقى محفوظاً مع الاجتماع المؤرشف.',
            'file_object_id' => $fileId,
            'recorded_by' => $manager->id,
            'recorded_at' => now(),
        ]);
        DB::table('attachment_links')->insert([
            'file_object_id' => $fileId,
            'project_id' => $project->id,
            'meeting_minutes_id' => $minutes->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)->postJson(route('projects.timeline-entries.archive', [$project, $timeline]), [
            'lock_version' => 1,
        ])
            ->assertForbidden();
        $this->actingAs($manager)->postJson(route('projects.meetings.archive', [$project, $meeting]), [
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.id', $meeting->id)
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.timeline_entry.lock_version', 2);

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
        $this->assertDatabaseHas('timeline_entries', ['id' => $timeline->id]);
        $this->assertDatabaseCount('meeting_minutes', 1);
        $this->assertNotNull($meeting->fresh()->archived_at);
        $this->assertNotNull($timeline->fresh()->archived_at);
        $this->assertNotNull(DB::table('attachment_links')->where('meeting_minutes_id', $minutes->id)->value('archived_at'));
        $this->actingAs($manager)->getJson(route('projects.meetings.index', $project))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($manager)->getJson(route('projects.meetings.index', [$project, 'archived' => 1]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('activity_logs', ['action' => 'meeting.archived', 'subject_id' => $meeting->id]);

        $this->actingAs($manager)->postJson(route('projects.meetings.restore', [$project, $meeting]), [
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertNotNull($meeting->fresh()->archived_at);
        $this->assertNotNull($timeline->fresh()->archived_at);
        $this->actingAs($manager)->postJson(route('projects.meetings.restore', [$project, $meeting]), [
            'lock_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.archived_at', null)
            ->assertJsonPath('data.lock_version', 3)
            ->assertJsonPath('data.timeline_entry.lock_version', 3);
        $this->assertNull($meeting->fresh()->archived_at);
        $this->assertNull($timeline->fresh()->archived_at);
        $this->assertNull(DB::table('attachment_links')->where('meeting_minutes_id', $minutes->id)->value('archived_at'));
        $this->assertDatabaseCount('meeting_minutes', 1);
        $this->assertDatabaseHas('activity_logs', ['action' => 'meeting.restored', 'subject_id' => $meeting->id]);
    }

    private function createMeeting(int $organizerId, int $projectId): Meeting
    {
        $timeline = TimelineEntry::query()->create([
            'project_id' => $projectId,
            'kind' => 'meeting',
            'title' => 'اجتماع تجريبي',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'planned',
            'owner_id' => $organizerId,
        ]);

        return Meeting::query()->create([
            'timeline_entry_id' => $timeline->id,
            'organizer_id' => $organizerId,
        ]);
    }

    private function insertSafeFile(int $uploaderId, string $name): int
    {
        return (int) DB::table('file_objects')->insertGetId([
            'disk' => 'local',
            'storage_key' => 'tests/'.$name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'scan_status' => 'safe',
            'uploaded_by' => $uploaderId,
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
