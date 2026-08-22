<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DataJob;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProjectDeskTestData;
use Tests\Support\RegistersDataCenterRoutes;
use Tests\TestCase;

class DataCenterCsvTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase, RegistersDataCenterRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->registerDataCenterRoutes();
    }

    public function test_only_active_admin_can_use_data_center(): void
    {
        $manager = $this->makeUser('project_manager');
        $inactiveAdmin = $this->makeUser('admin', 'inactive');

        foreach ([$manager, $inactiveAdmin] as $user) {
            $this->actingAs($user)->getJson(route('data-center.jobs.index'))->assertForbidden();
            $this->actingAs($user)->postJson(route('data-center.csv.preview', 'clients'), [
                'file' => UploadedFile::fake()->createWithContent('clients.csv', "code,name,email,phone,address,status\nCL-1,Test,,,,active\n"),
            ])->assertForbidden();
        }
    }

    public function test_templates_and_exports_have_utf8_bom_fixed_headers_and_formula_protection(): void
    {
        $admin = $this->makeUser('admin');
        Client::query()->create([
            'code' => 'CL-CSV',
            'name' => '=FORMULA',
            'email' => 'client@example.test',
            'status' => 'active',
        ]);

        $template = $this->actingAs($admin)->get(route('data-center.csv.template', 'clients'));
        $template->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBFcode,name,email,phone,address,status", $template->streamedContent());

        $export = $this->actingAs($admin)->get(route('data-center.csv.export', 'clients'));
        $export->assertOk();
        $this->assertStringContainsString("'=FORMULA", $export->streamedContent());
        $this->assertDatabaseHas('data_jobs', ['type' => 'export', 'resource_type' => 'clients', 'status' => 'succeeded']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'data_export.created']);
    }

    public function test_project_and_task_templates_and_exports_use_their_fixed_contracts(): void
    {
        $admin = $this->makeUser('admin');
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $taskStatus = $this->makeStatus('task', 'csv-export-open', 'open');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-EXPORT',
            'title' => 'مهمة للتصدير',
            'status_id' => $taskStatus->id,
            'priority' => 'medium',
            'assignee_id' => $manager->id,
            'assigned_at' => '2026-08-12 07:00:00',
            'start_at' => '2026-08-12 08:00:00',
            'due_at' => '2026-08-13 15:00:00',
        ]);

        $projectTemplate = $this->actingAs($admin)->get(route('data-center.csv.template', 'projects'));
        $projectTemplate->assertOk();
        $this->assertStringStartsWith(
            "\xEF\xBB\xBFcode,name,description,client_code,manager_email,status_code,priority,start_date,end_date",
            $projectTemplate->streamedContent(),
        );
        $taskTemplate = $this->get(route('data-center.csv.template', 'tasks'));
        $taskTemplate->assertOk();
        $this->assertStringStartsWith(
            "\xEF\xBB\xBFproject_code,code,title,description,status_code,priority,assignee_email,assigned_at,start_at,due_at,estimated_minutes,notes",
            $taskTemplate->streamedContent(),
        );

        $projectExport = $this->get(route('data-center.csv.export', 'projects'));
        $projectExport->assertOk();
        $projectCsv = $projectExport->streamedContent();
        $this->assertStringContainsString($project->code, $projectCsv);
        $this->assertStringContainsString($project->client->code, $projectCsv);
        $this->assertStringContainsString($manager->email, $projectCsv);

        $taskExport = $this->get(route('data-center.csv.export', 'tasks'));
        $taskExport->assertOk();
        $taskCsv = $taskExport->streamedContent();
        $this->assertStringContainsString($project->code, $taskCsv);
        $this->assertStringContainsString($task->code, $taskCsv);
        $this->assertStringContainsString($manager->email, $taskCsv);
        $this->assertDatabaseHas('data_jobs', [
            'type' => 'export',
            'resource_type' => 'projects',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('data_jobs', [
            'type' => 'export',
            'resource_type' => 'tasks',
            'status' => 'succeeded',
        ]);
    }

    public function test_client_preview_profiles_and_validates_then_commit_is_transactional_and_idempotency_guarded(): void
    {
        $admin = $this->makeUser('admin');
        $existingClient = Client::query()->create(['code' => 'CL-OLD', 'name' => 'Old Name', 'status' => 'active']);
        $csv = implode("\n", [
            'code,name,email,phone,address,status',
            'CL-OLD,  Updated Name  ,UPDATED@EXAMPLE.TEST,,,active',
            'cl-new,عميل جديد,new@example.test,+218910000000,طرابلس,active',
        ])."\n";

        $preview = $this->actingAs($admin)->postJson(route('data-center.csv.preview', 'clients'), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', "\xEF\xBB\xBF".$csv),
        ]);
        $preview->assertCreated();
        if ($preview->json('data.status') !== 'validated') {
            $this->fail('Unexpected preview state: '.var_export($preview->json(), true));
        }
        $preview
            ->assertJsonPath('data.summary.row_count', 2)
            ->assertJsonPath('data.summary.actions.create', 1)
            ->assertJsonPath('data.summary.actions.update', 1)
            ->assertJsonPath('data.summary.transformations.bom_removed', true);
        $job = DataJob::query()->firstOrFail();
        $checksum = (string) data_get($job->summary, 'checksum_sha256');
        $recordVersions = data_get($job->summary, 'record_versions');
        $this->assertIsArray($recordVersions);
        $this->assertArrayHasKey('CL-OLD', $recordVersions);
        $this->assertArrayHasKey('CL-NEW', $recordVersions);
        $this->assertIsString($recordVersions['CL-OLD']);
        $this->assertStringStartsWith(
            (string) $existingClient->getRawOriginal('updated_at').'|',
            $recordVersions['CL-OLD'],
        );
        $this->assertNull($recordVersions['CL-NEW']);
        $this->assertDatabaseCount('clients', 1);

        $this->actingAs($admin)->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => str_repeat('0', 64),
        ])->assertUnprocessable()->assertJsonValidationErrors('checksum');
        $this->assertDatabaseCount('clients', 1);

        $this->actingAs($admin)->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => $checksum,
        ])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseHas('clients', ['code' => 'CL-OLD', 'name' => 'Updated Name', 'email' => 'updated@example.test']);
        $this->assertDatabaseHas('clients', ['code' => 'CL-NEW', 'name' => 'عميل جديد']);

        $this->actingAs($admin)->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => $checksum,
        ])->assertUnprocessable()->assertJsonValidationErrors('data_job');
        $this->assertDatabaseCount('clients', 2);
    }

    public function test_client_import_rejects_a_stale_preview_without_partial_writes(): void
    {
        $admin = $this->makeUser('admin');
        $client = Client::query()->create([
            'code' => 'CL-CONFLICT',
            'name' => 'Snapshot name',
            'phone' => '+218910000001',
            'status' => 'active',
        ]);
        $csv = implode("\n", [
            'code,name,email,phone,address,status',
            'CL-WRITE-FIRST,New client,,,,active',
            'CL-CONFLICT,Imported overwrite,,+218910000002,,active',
        ])."\n";

        $preview = $this->actingAs($admin)->postJson(route('data-center.csv.preview', 'clients'), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ]);
        $preview->assertCreated()->assertJsonPath('data.status', 'validated');
        $job = DataJob::query()->firstOrFail();
        $version = data_get($job->summary, 'record_versions.CL-CONFLICT');
        $this->assertIsString($version);

        $client->update([
            'name' => 'Newer interactive edit',
            'phone' => '+218910000099',
        ]);

        $this->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => (string) data_get($job->summary, 'checksum_sha256'),
        ])->assertUnprocessable()->assertJsonValidationErrors('data_job');

        $this->assertDatabaseMissing('clients', ['code' => 'CL-WRITE-FIRST']);
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'name' => 'Newer interactive edit',
            'phone' => '+218910000099',
        ]);
        $this->assertDatabaseHas('data_jobs', ['id' => $job->id, 'status' => 'validated']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'client.import_created']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'client.import_updated']);
    }

    public function test_invalid_csv_records_every_error_and_cannot_commit(): void
    {
        $admin = $this->makeUser('admin');
        $csv = implode("\n", [
            'code,name,email,phone,address,status',
            'CL-X,=HYPERLINK,bad-email,,,unknown',
            'CL-X,Duplicate,,,,active',
        ])."\n";

        $response = $this->actingAs($admin)->postJson(route('data-center.csv.preview', 'clients'), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'validation_failed')->assertJsonPath('data.summary.can_commit', false);
        $job = DataJob::query()->firstOrFail();
        $this->assertGreaterThanOrEqual(3, $job->importErrors()->count());
        $this->assertDatabaseHas('import_errors', ['code' => 'spreadsheet_formula']);
        $this->assertDatabaseHas('import_errors', ['code' => 'duplicate_row']);

        $this->actingAs($admin)->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => (string) data_get($job->summary, 'checksum_sha256'),
        ])->assertUnprocessable()->assertJsonValidationErrors('data_job');
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_task_import_validates_scope_dates_status_and_commits_assignment_event(): void
    {
        $admin = $this->makeUser('admin');
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $taskStatus = $this->makeStatus('task', 'csv-open', 'open');
        $csv = implode("\n", [
            'project_code,code,title,description,status_code,priority,assignee_email,assigned_at,start_at,due_at,estimated_minutes,notes',
            "{$project->code},,مهمة مستوردة,وصف,{$taskStatus->code},high,{$member->email},2026-08-12 08:30:00,2026-08-12 09:00:00,2026-08-14 17:00:00,120,ملاحظة",
        ])."\n";

        $preview = $this->actingAs($admin)->postJson(route('data-center.csv.preview', 'tasks'), [
            'file' => UploadedFile::fake()->createWithContent('tasks.csv', $csv),
        ]);
        $preview->assertCreated();
        if ($preview->json('data.status') !== 'validated') {
            $this->fail('Unexpected preview state: '.var_export($preview->json(), true));
        }
        $job = DataJob::query()->firstOrFail();
        $this->actingAs($admin)->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => (string) data_get($job->summary, 'checksum_sha256'),
        ])->assertOk();

        $task = Task::query()->firstOrFail();
        $this->assertSame('TSK-00001', $task->code);
        $this->assertSame($member->id, $task->assignee_id);
        $this->assertSame('2026-08-12 07:00:00', $task->start_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('task_assignment_events', [
            'task_id' => $task->id,
            'from_user_id' => null,
            'to_user_id' => $member->id,
            'note' => 'استيراد CSV',
        ]);
    }

    public function test_task_import_rejects_a_stale_preview_instead_of_overwriting_a_newer_edit(): void
    {
        $admin = $this->makeUser('admin');
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $taskStatus = $this->makeStatus('task', 'csv-lock-open', 'open');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-LOCK',
            'title' => 'قبل المعاينة',
            'status_id' => $taskStatus->id,
            'priority' => 'medium',
            'start_at' => '2026-08-12 08:00:00',
            'due_at' => '2026-08-13 15:00:00',
            'lock_version' => 1,
        ]);
        $csv = implode("\n", [
            'project_code,code,title,description,status_code,priority,assignee_email,assigned_at,start_at,due_at,estimated_minutes,notes',
            "{$project->code},TSK-WOULD-WRITE,New task before conflict,,{$taskStatus->code},medium,,,2026-08-12 09:00:00,2026-08-14 17:00:00,,",
            "{$project->code},{$task->code},تعديل الاستيراد,,{$taskStatus->code},high,,,2026-08-12 09:00:00,2026-08-14 17:00:00,,",
        ])."\n";

        $preview = $this->actingAs($admin)->postJson(route('data-center.csv.preview', 'tasks'), [
            'file' => UploadedFile::fake()->createWithContent('tasks.csv', $csv),
        ]);
        $preview->assertCreated()->assertJsonPath('data.status', 'validated');
        $job = DataJob::query()->firstOrFail();
        $task->update(['title' => 'تعديل أحدث', 'lock_version' => 2]);

        $this->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => (string) data_get($job->summary, 'checksum_sha256'),
        ])->assertUnprocessable()->assertJsonValidationErrors('data_job');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'تعديل أحدث',
            'lock_version' => 2,
        ]);
        $this->assertDatabaseMissing('tasks', [
            'project_id' => $project->id,
            'code' => 'TSK-WOULD-WRITE',
        ]);
        $this->assertDatabaseHas('data_jobs', ['id' => $job->id, 'status' => 'validated']);
    }

    public function test_task_import_batch_update_preserves_reassignment_and_optimistic_lock_history(): void
    {
        $admin = $this->makeUser('admin');
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $taskStatus = $this->makeStatus('task', 'csv-update-done', 'done');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-BATCH-UPDATE',
            'title' => 'Before import',
            'status_id' => $taskStatus->id,
            'priority' => 'medium',
            'assignee_id' => $manager->id,
            'assigned_at' => '2026-08-11 08:00:00',
            'start_at' => '2026-08-11 09:00:00',
            'due_at' => '2026-08-12 17:00:00',
            'lock_version' => 4,
        ]);
        $csv = implode("\n", [
            'project_code,code,title,description,status_code,priority,assignee_email,assigned_at,start_at,due_at,estimated_minutes,notes',
            "{$project->code},{$task->code},After import,,{$taskStatus->code},high,{$member->email},2026-08-12 08:30:00,2026-08-12 09:00:00,2026-08-14 17:00:00,120,Updated in batch",
        ])."\n";

        $preview = $this->actingAs($admin)->postJson(route('data-center.csv.preview', 'tasks'), [
            'file' => UploadedFile::fake()->createWithContent('task-update.csv', $csv),
        ]);
        $preview->assertCreated()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.summary.actions.update', 1);
        $job = DataJob::query()->findOrFail((int) $preview->json('data.id'));
        $this->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => (string) data_get($job->summary, 'checksum_sha256'),
        ])->assertOk();

        $task->refresh();
        $this->assertSame('After import', $task->title);
        $this->assertSame('high', $task->priority);
        $this->assertSame($member->id, $task->assignee_id);
        $this->assertSame(5, $task->lock_version);
        $this->assertNotNull($task->completed_at);
        $this->assertDatabaseHas('task_assignment_events', [
            'task_id' => $task->id,
            'from_user_id' => $manager->id,
            'to_user_id' => $member->id,
            'recorded_by' => $admin->id,
        ]);
        $this->assertNotNull(DB::table('task_assignment_events')->where('task_id', $task->id)->value('created_at'));
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'project_id' => $project->id,
            'action' => 'task.import_updated',
        ]);
    }

    public function test_task_import_query_count_is_batched_for_one_thousand_rows(): void
    {
        $admin = $this->makeUser('admin');
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $taskStatus = $this->makeStatus('task', 'csv-volume-open', 'open');

        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'pragma')) {
                $queryCount++;
            }
        });

        $this->importTaskVolume($admin, $project, $taskStatus, $member, 'SMALL', 10);
        $smallImportQueries = $queryCount;

        $queryCount = 0;
        $this->importTaskVolume($admin, $project, $taskStatus, $member, 'LARGE', 1000);
        $largeImportQueries = $queryCount;

        $this->assertLessThanOrEqual(
            180,
            $largeImportQueries,
            "A 1,000-row task import used {$largeImportQueries} queries; lookups or writes regressed to per-row access.",
        );
        $this->assertLessThan(
            $smallImportQueries * 10,
            $largeImportQueries,
            "Query growth was too close to row growth ({$smallImportQueries} for 10 rows, {$largeImportQueries} for 1,000).",
        );
        $this->assertDatabaseCount('tasks', 1010);
        $this->assertDatabaseCount('task_assignment_events', 1010);
        $this->assertSame(1010, DB::table('activity_logs')->whereIn('action', [
            'task.import_created',
            'task.import_updated',
        ])->count());
        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'code' => 'LARGE-0001',
            'assignee_id' => $member->id,
        ]);
        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'code' => 'LARGE-1000',
            'assignee_id' => $member->id,
        ]);
    }

    private function importTaskVolume(
        User $admin,
        Project $project,
        WorkflowStatus $status,
        User $member,
        string $prefix,
        int $rowCount,
    ): void {
        $rows = [
            'project_code,code,title,description,status_code,priority,assignee_email,assigned_at,start_at,due_at,estimated_minutes,notes',
        ];
        foreach (range(1, $rowCount) as $number) {
            $rows[] = implode(',', [
                $project->code,
                $prefix.'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                "Volume task {$number}",
                '',
                $status->code,
                'medium',
                $member->email,
                '2026-08-12 08:30:00',
                '2026-08-12 09:00:00',
                '2026-08-14 17:00:00',
                '60',
                '',
            ]);
        }

        $preview = $this->actingAs($admin)->postJson(route('data-center.csv.preview', 'tasks'), [
            'file' => UploadedFile::fake()->createWithContent(strtolower($prefix).'-tasks.csv', implode("\n", $rows)."\n"),
        ]);
        $preview->assertCreated()->assertJsonPath('data.status', 'validated');
        $job = DataJob::query()->findOrFail((int) $preview->json('data.id'));
        $this->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => (string) data_get($job->summary, 'checksum_sha256'),
        ])->assertOk()->assertJsonPath('data.status', 'succeeded');
    }
}
