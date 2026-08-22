<?php

namespace Tests\Feature;

use App\Contracts\MalwareScanner;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\ProjectFileController;
use App\Http\Controllers\RequirementBookController;
use App\Models\FileObject;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\RequirementBookVersion;
use App\Models\TimelineEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeMalwareScanner;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ProjectDocumentWorkflowTest extends TestCase
{
    use ProjectDeskTestData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('project-desk.uploads.disk', 'local');
        $this->app->instance(MalwareScanner::class, FakeMalwareScanner::clean());
        $this->registerDocumentRoutes();
    }

    public function test_project_file_is_private_authorized_and_never_exposes_storage_coordinates(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $outsider = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);

        $response = $this->actingAs($member)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('../كراسة المتطلبات.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated()
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonMissingPath('data.storage_key')
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.checksum_sha256');

        $file = FileObject::query()->firstOrFail();
        $this->assertSame('safe', $file->scan_status);
        $this->assertSame('كراسة المتطلبات.pdf', $file->original_name);
        $this->assertStringNotContainsString('كراسة المتطلبات', $file->storage_key);
        $this->assertSame(64, strlen($file->checksum_sha256));
        Storage::disk('local')->assertExists($file->storage_key);
        $this->assertDatabaseHas('attachment_links', [
            'project_id' => $project->id,
            'file_object_id' => $file->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.uploaded',
            'subject_id' => $file->id,
        ]);

        $this->actingAs($member)->get(route('files.download', $file))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('cache-control', 'max-age=0, no-store, private');
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.downloaded',
            'subject_id' => $file->id,
        ]);
        $this->actingAs($outsider)->get(route('files.download', $file))->assertForbidden();

        $this->actingAs($member)->getJson(route('projects.files.index', $project))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $response->json('data.id'))
            ->assertJsonMissingPath('data.0.storage_key');
    }

    public function test_project_file_can_be_archived_without_deleting_the_file_or_history(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $fileId = $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('archive-me.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated()->json('data.id');
        $file = FileObject::query()->findOrFail($fileId);

        $this->actingAs($manager)->postJson(route('projects.files.archive', [$project, $file]))
            ->assertOk();

        $this->assertDatabaseHas('file_objects', ['id' => $file->id]);
        $this->assertDatabaseHas('attachment_links', [
            'file_object_id' => $file->id,
            'project_id' => $project->id,
        ]);
        $this->assertNotNull($file->attachmentLinks()->firstOrFail()->archived_at);
        Storage::disk('local')->assertExists($file->storage_key);
        $this->actingAs($manager)->get(route('files.download', $file))->assertForbidden();
        $this->actingAs($manager)->getJson(route('projects.files.index', $project))
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($manager)->getJson(route('projects.files.index', [
            'project' => $project,
            'include_archived' => 1,
        ]))
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $file->id)
            ->assertJsonPath('data.0.can_restore', true)
            ->assertJsonPath('data.0.download_url', null);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.archived',
            'subject_id' => $file->id,
        ]);

        $this->actingAs($manager)->postJson(route('projects.files.restore', [$project, $file]))
            ->assertOk();

        $this->assertNull($file->attachmentLinks()->firstOrFail()->fresh()->archived_at);
        $this->actingAs($manager)->get(route('files.download', $file))->assertOk();
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.restored',
            'subject_id' => $file->id,
        ]);
    }

    public function test_upload_rejects_forbidden_spoofed_and_oversized_files(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => UploadedFile::fake()->createWithContent('payload.exe', "MZ\0\0")],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => UploadedFile::fake()->createWithContent('spoofed.pdf', 'plain text only')],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('file');

        config()->set('project-desk.uploads.max_file_kilobytes', 1);
        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => UploadedFile::fake()->create('large.pdf', 2, 'application/pdf')],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('file_objects', 0);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.upload_rejected_validation',
            'subject_id' => $project->id,
        ]);
    }

    public function test_requirement_book_versions_keep_one_current_and_archive_without_deletion(): void
    {
        $manager = $this->makeUser('project_manager');
        $viewer = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($viewer, ['project_role' => 'member', 'status' => 'active']);

        $first = $this->actingAs($manager)->post(
            route('projects.requirement-book.versions.store', $project),
            [
                'title' => 'الكراسة الأساسية',
                'version_number' => 1,
                'status' => 'approved',
                'note' => 'الإصدار المعتمد الأول',
                'is_current' => true,
                'file' => $this->pdf('requirements-v1.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.is_current', true);

        $second = $this->actingAs($manager)->post(
            route('projects.requirement-book.versions.store', $project),
            [
                'title' => 'الكراسة المحدثة',
                'version_number' => 2,
                'status' => 'under_review',
                'note' => 'مراجعة النطاق الجديد',
                'is_current' => false,
                'file' => $this->pdf('requirements-v2.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.is_current', false);

        $firstId = (int) $first->json('data.id');
        $secondId = (int) $second->json('data.id');
        $secondFileId = (int) $second->json('data.file.id');
        $secondLock = (int) $second->json('data.lock_version');
        $madeCurrent = $this->actingAs($manager)->postJson(
            route('projects.requirement-book.versions.current', [$project, $secondId]),
            ['lock_version' => $secondLock],
        )->assertOk()->assertJsonPath('data.is_current', true);

        $currentLock = (int) $madeCurrent->json('data.lock_version');
        $archived = $this->actingAs($manager)->postJson(
            route('projects.requirement-book.versions.archive', [$project, $secondId]),
            ['lock_version' => $currentLock],
        )->assertOk()->assertJsonPath('data.is_current', false);

        $this->assertDatabaseHas('requirement_book_versions', [
            'id' => $secondId,
            'is_current' => false,
        ]);
        $this->assertNotNull(RequirementBookVersion::query()->findOrFail($secondId)->archived_at);
        $this->assertDatabaseHas('attachment_links', [
            'requirement_book_version_id' => $secondId,
            'file_object_id' => $secondFileId,
        ]);
        $this->assertNotNull(FileObject::query()->findOrFail($secondFileId)->attachmentLinks()->firstOrFail()->archived_at);
        $this->assertTrue(RequirementBookVersion::query()->findOrFail($firstId)->is_current);
        $this->assertDatabaseCount('requirement_book_versions', 2);
        $this->assertDatabaseCount('file_objects', 2);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'requirement_book.version_archived',
            'subject_id' => $secondId,
        ]);

        $this->actingAs($manager)->getJson(route('projects.requirement-book.show', [
            'project' => $project,
            'include_archived' => 1,
        ]))->assertOk()->assertJsonCount(2, 'data.versions');
        $this->actingAs($viewer)->getJson(route('projects.requirement-book.show', [
            'project' => $project,
            'include_archived' => 1,
        ]))->assertOk()->assertJsonCount(1, 'data.versions');
        $this->actingAs($viewer)->get(route('files.download', $secondFileId))->assertForbidden();

        $restored = $this->actingAs($manager)->postJson(
            route('projects.requirement-book.versions.restore', [$project, $secondId]),
            ['lock_version' => (int) $archived->json('data.lock_version')],
        )->assertOk()->assertJsonPath('data.archived_at', null);
        self::assertGreaterThan((int) $archived->json('data.lock_version'), (int) $restored->json('data.lock_version'));
        $this->assertNull(FileObject::query()->findOrFail($secondFileId)->attachmentLinks()->firstOrFail()->archived_at);
        $this->actingAs($viewer)->get(route('files.download', $secondFileId))->assertOk();
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'requirement_book.version_restored',
            'subject_id' => $secondId,
        ]);

        $this->actingAs($viewer)->post(
            route('projects.requirement-book.versions.store', $project),
            ['title' => 'غير مصرح', 'file' => $this->pdf('forbidden.pdf')],
            ['Accept' => 'application/json'],
        )->assertForbidden();
    }

    public function test_requirement_book_rejects_duplicate_versions_stale_writes_and_archived_projects(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $payload = [
            'title' => 'كراسة المشروع',
            'version_number' => 7,
            'file' => $this->pdf('requirements-v7.pdf'),
        ];

        $created = $this->actingAs($manager)->post(
            route('projects.requirement-book.versions.store', $project),
            $payload,
            ['Accept' => 'application/json'],
        )->assertCreated();

        $this->actingAs($manager)->post(
            route('projects.requirement-book.versions.store', $project),
            [...$payload, 'file' => $this->pdf('requirements-v7-duplicate.pdf')],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('version_number');
        $this->assertDatabaseCount('file_objects', 1);

        $versionId = (int) $created->json('data.id');
        $lock = (int) $created->json('data.lock_version');
        $this->actingAs($manager)->putJson(
            route('projects.requirement-book.versions.update', [$project, $versionId]),
            ['lock_version' => $lock, 'note' => 'ملاحظة جديدة'],
        )->assertOk();
        $this->actingAs($manager)->putJson(
            route('projects.requirement-book.versions.update', [$project, $versionId]),
            ['lock_version' => $lock, 'note' => 'كتابة قديمة'],
        )->assertUnprocessable()->assertJsonValidationErrors('lock_version');

        $project->update(['archived_at' => now()]);
        $this->actingAs($manager)->post(
            route('projects.requirement-book.versions.store', $project),
            ['title' => 'بعد الأرشفة', 'file' => $this->pdf('archived-project.pdf')],
            ['Accept' => 'application/json'],
        )->assertForbidden();
    }

    public function test_minutes_accept_direct_attachment_and_reject_cross_project_reuse(): void
    {
        $manager = $this->makeUser('project_manager');
        $firstProject = $this->makeProject($manager);
        $secondProject = $this->makeProject($manager, $firstProject->status);
        $meeting = $this->createMeeting($manager->id, $secondProject->id);

        $uploaded = $this->actingAs($manager)->post(
            route('projects.files.store', $firstProject),
            ['file' => $this->pdf('first-project.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated();

        $this->actingAs($manager)->putJson(
            route('projects.meetings.minutes.upsert', [$secondProject, $meeting]),
            [
                'summary' => 'محضر لا يجوز ربطه بملف مشروع آخر.',
                'file_object_id' => (int) $uploaded->json('data.id'),
            ],
        )->assertUnprocessable()->assertJsonValidationErrors('file_object_id');

        $saved = $this->actingAs($manager)->put(
            route('projects.meetings.minutes.upsert', [$secondProject, $meeting]),
            [
                'summary' => 'محضر الاجتماع النهائي.',
                'decisions' => 'اعتماد نطاق العمل.',
                'attachment' => $this->pdf('meeting-minutes.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('data.summary', 'محضر الاجتماع النهائي.')
            ->assertJsonMissingPath('data.file.storage_key');

        $minutes = MeetingMinutes::query()->firstOrFail();
        $this->assertSame($saved->json('data.file_object_id'), $minutes->file_object_id);
        $this->assertDatabaseHas('attachment_links', [
            'project_id' => $secondProject->id,
            'meeting_minutes_id' => $minutes->id,
            'file_object_id' => $minutes->file_object_id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'meeting_minutes.created',
            'subject_id' => $minutes->id,
        ]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
        );
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

    private function registerDocumentRoutes(): void
    {
        if (Route::has('projects.files.store')) {
            return;
        }

        Route::middleware('web')->group(function (): void {
            Route::get('files/{fileObject}/download', [ProjectFileController::class, 'download'])->name('files.download');
            Route::scopeBindings()->group(function (): void {
                Route::get('projects/{project}/files', [ProjectFileController::class, 'index'])->name('projects.files.index');
                Route::post('projects/{project}/files', [ProjectFileController::class, 'store'])->name('projects.files.store');
                Route::get('projects/{project}/requirement-book', [RequirementBookController::class, 'show'])->name('projects.requirement-book.show');
                Route::post('projects/{project}/requirement-book/versions', [RequirementBookController::class, 'storeVersion'])->name('projects.requirement-book.versions.store');
                Route::put('projects/{project}/requirement-book/versions/{requirementBookVersion}', [RequirementBookController::class, 'updateVersion'])->name('projects.requirement-book.versions.update');
                Route::post('projects/{project}/requirement-book/versions/{requirementBookVersion}/make-current', [RequirementBookController::class, 'makeCurrent'])->name('projects.requirement-book.versions.current');
                Route::post('projects/{project}/requirement-book/versions/{requirementBookVersion}/archive', [RequirementBookController::class, 'archiveVersion'])->name('projects.requirement-book.versions.archive');
                Route::post('projects/{project}/requirement-book/versions/{requirementBookVersion}/restore', [RequirementBookController::class, 'restoreVersion'])->name('projects.requirement-book.versions.restore');
                Route::put('projects/{project}/meetings/{meeting}/minutes', [MeetingController::class, 'upsertMinutes'])->name('projects.meetings.minutes.upsert');
            });
        });
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}
