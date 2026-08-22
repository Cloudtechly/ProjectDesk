<?php

namespace Tests\Feature;

use App\Contracts\MalwareScanner;
use App\Models\FileObject;
use App\Security\MalwareScanResult;
use App\Security\NullMalwareScanner;
use App\Services\ProjectFileScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeMalwareScanner;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ProjectFileSecurityTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('project-desk.uploads.disk', 'local');
    }

    public function test_clean_scan_is_the_only_state_that_can_be_downloaded(): void
    {
        $this->app->instance(MalwareScanner::class, FakeMalwareScanner::clean());
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);

        $fileId = $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('clean.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.scan_status', 'safe')->json('data.id');

        $file = FileObject::query()->findOrFail($fileId);
        $this->get(route('files.download', $file))->assertOk();
    }

    public function test_eicar_detection_is_quarantined_logged_and_never_downloadable(): void
    {
        $this->app->instance(MalwareScanner::class, new FakeMalwareScanner(
            fn (string $path): MalwareScanResult => str_contains((string) file_get_contents($path), 'EICAR')
                ? MalwareScanResult::infected('Eicar-Test-Signature')
                : MalwareScanResult::clean(),
        ));
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('eicar.pdf', 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('file');

        $file = FileObject::query()->sole();
        self::assertSame('quarantined', $file->scan_status);
        $this->get(route('files.download', $file))->assertForbidden();
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.malware_rejected',
            'subject_id' => $file->id,
        ]);
    }

    public function test_scanner_failure_and_unconfigured_local_upload_both_fail_closed(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $this->app->instance(MalwareScanner::class, FakeMalwareScanner::failed());

        $failedId = $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('scanner-failure.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.download_url', null)->json('data.id');
        $failed = FileObject::query()->findOrFail($failedId);
        self::assertSame('structurally_safe', $failed->scan_status);
        $this->get(route('files.download', $failed))->assertForbidden();
        $this->assertDatabaseHas('activity_logs', ['action' => 'project_file.scan_failed', 'subject_id' => $failed->id]);

        $this->app->instance(MalwareScanner::class, new NullMalwareScanner);
        $pendingId = $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('unconfigured.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.download_url', null)->json('data.id');
        $pending = FileObject::query()->findOrFail($pendingId);
        self::assertSame('structurally_safe', $pending->scan_status);
        $this->get(route('files.download', $pending))->assertForbidden();

        $this->actingAs($manager)
            ->getJson(route('projects.files.index', $project))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.scan_status', 'structurally_safe')
            ->assertJsonPath('data.0.download_url', null)
            ->assertJsonPath('data.1.scan_status', 'structurally_safe')
            ->assertJsonPath('data.1.download_url', null);
    }

    public function test_project_quota_rejects_the_next_upload_and_audits_the_attempt(): void
    {
        $this->app->instance(MalwareScanner::class, FakeMalwareScanner::clean());
        config()->set('project-desk.uploads.project_quota_bytes', 150);
        config()->set('project-desk.uploads.user_project_quota_bytes', 150);
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('first.pdf', str_repeat('a', 50))],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('second.pdf', str_repeat('b', 50))],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('file_objects', 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.upload_rejected_quota',
            'subject_id' => $project->id,
        ]);
    }

    public function test_production_rejects_upload_when_scanner_is_not_configured(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $this->app->instance(MalwareScanner::class, new NullMalwareScanner);
        $previous = $this->app['env'];
        $this->app['env'] = 'production';
        $this->actingAs($manager);
        try {
            $this->app->make(ProjectFileScanner::class)->ensureUploadAvailable($project, $manager);
            self::fail('Production uploads must be rejected without a configured scanner.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('file', $exception->errors());
        } finally {
            $this->app['env'] = $previous;
        }

        $this->assertDatabaseCount('file_objects', 0);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.upload_rejected_scanner_unavailable',
            'subject_id' => $project->id,
        ]);
    }

    public function test_upload_rate_limit_is_scoped_to_user_and_project_and_audited(): void
    {
        $this->app->instance(MalwareScanner::class, FakeMalwareScanner::clean());
        config()->set('project-desk.uploads.rate_limit_per_minute', 1);
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('allowed.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('throttled.pdf')],
            ['Accept' => 'application/json'],
        )->assertTooManyRequests();

        $this->assertDatabaseCount('file_objects', 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_file.upload_rejected_rate_limit',
            'subject_id' => $project->id,
        ]);
    }

    private function pdf(string $name, string $body = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n{$body}\n%%EOF",
        );
    }
}
