<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DataJob;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\ProjectDeskTestData;
use Tests\Support\RegistersDataCenterRoutes;
use Tests\TestCase;
use ZipArchive;

class DataCenterXlsxTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase, RegistersDataCenterRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->registerDataCenterRoutes();
    }

    public function test_xlsx_templates_and_exports_are_real_private_workbooks_with_formula_protection(): void
    {
        $admin = $this->makeUser();
        Client::query()->create([
            'created_by' => $admin->id,
            'code' => 'CL-XLSX',
            'name' => '=HYPERLINK("https://attacker.invalid","open")',
            'email' => 'client@example.test',
            'status' => 'active',
        ]);

        $template = $this->actingAs($admin)->get(route('data-center.xlsx.template', 'clients'));
        $template->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('x-content-type-options', 'nosniff');
        $templateWorkbook = $this->loadResponseWorkbook($template->baseResponse);
        $this->assertSame(1, $templateWorkbook->getProperties()->getCustomPropertyValue('project_desk_template_version'));
        $this->assertSame('clients', $templateWorkbook->getProperties()->getCustomPropertyValue('project_desk_resource'));
        $templateSheet = $templateWorkbook->getActiveSheet();
        $this->assertSame('code', $templateSheet->getCell('A1')->getValue());
        $this->assertSame('status', $templateSheet->getCell('F1')->getValue());

        $export = $this->get(route('data-center.xlsx.export', 'clients'));
        $export->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('private', (string) $export->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $export->headers->get('cache-control'));
        $sheet = $this->loadResponseWorkbook($export->baseResponse)->getActiveSheet();
        $this->assertSame("'=HYPERLINK(\"https://attacker.invalid\",\"open\")", $sheet->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
        $this->assertDatabaseHas('data_jobs', [
            'type' => 'export',
            'resource_type' => 'clients',
            'format' => 'xlsx',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'data_export.created']);
    }

    public function test_xlsx_preview_and_atomic_commit_reuse_the_validated_import_pipeline(): void
    {
        $admin = $this->makeUser();
        $upload = $this->workbookUpload('clients.xlsx', 'Clients Upload', [
            ['code', 'name', 'email', 'phone', 'address', 'status'],
            ['CL-EXCEL-1', 'عميل Excel', 'excel@example.test', '+218910000001', 'طرابلس', 'active'],
        ]);

        $preview = $this->actingAs($admin)->postJson(route('data-center.xlsx.preview', 'clients'), ['file' => $upload]);
        $preview->assertCreated()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.format', 'xlsx')
            ->assertJsonPath('data.summary.sheet', 'Clients Upload')
            ->assertJsonPath('data.summary.template_version', 1)
            ->assertJsonPath('data.summary.can_commit', true);
        $job = DataJob::query()->findOrFail((int) $preview->json('data.id'));
        $this->assertDatabaseMissing('clients', ['code' => 'CL-EXCEL-1']);

        $this->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => $preview->json('data.summary.checksum_sha256'),
        ])->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertDatabaseHas('clients', [
            'code' => 'CL-EXCEL-1',
            'name' => 'عميل Excel',
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'data_import.committed']);
    }

    public function test_xlsx_formula_and_validation_errors_retain_sheet_row_and_field_and_cannot_commit(): void
    {
        $admin = $this->makeUser();
        $upload = $this->workbookUpload('unsafe.xlsx', 'Client Errors', [
            ['code', 'name', 'email', 'phone', 'address', 'status'],
            ['CL-WOULD-WRITE', 'سجل صالح قبل الخطأ', 'valid@example.test', '', '', 'active'],
            ['CL-BAD', '=SUM(1,2)', 'not-an-email', '', '', 'active'],
        ], ['B3' => '=SUM(1,2)']);

        $response = $this->actingAs($admin)->postJson(route('data-center.xlsx.preview', 'clients'), ['file' => $upload]);
        $response->assertCreated()
            ->assertJsonPath('data.status', 'validation_failed')
            ->assertJsonPath('data.summary.can_commit', false);
        $job = DataJob::query()->findOrFail((int) $response->json('data.id'));
        $this->assertDatabaseHas('import_errors', [
            'data_job_id' => $job->id,
            'sheet' => 'Client Errors',
            'row_number' => 3,
            'field' => 'name',
            'code' => 'spreadsheet_formula',
        ]);
        $this->assertDatabaseHas('import_errors', [
            'data_job_id' => $job->id,
            'sheet' => 'Client Errors',
            'row_number' => 3,
            'field' => 'email',
            'code' => 'validation',
        ]);
        $this->postJson(route('data-center.imports.commit', $job), [
            'checksum_sha256' => $response->json('data.summary.checksum_sha256'),
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('clients', ['code' => 'CL-WOULD-WRITE']);
    }

    public function test_xlsx_import_rejects_an_unknown_fixed_template_version(): void
    {
        $admin = $this->makeUser();
        $upload = $this->workbookUpload('obsolete.xlsx', 'Clients', [
            ['code', 'name', 'email', 'phone', 'address', 'status'],
            ['CL-OLD-TEMPLATE', 'Old template', '', '', '', 'active'],
        ], templateVersion: 99);

        $response = $this->actingAs($admin)->postJson(route('data-center.xlsx.preview', 'clients'), ['file' => $upload]);
        $response->assertCreated()
            ->assertJsonPath('data.status', 'validation_failed')
            ->assertJsonPath('data.summary.can_commit', false);
        $this->assertDatabaseHas('import_errors', [
            'data_job_id' => (int) $response->json('data.id'),
            'sheet' => 'Clients',
            'row_number' => 1,
            'field' => null,
            'code' => 'template_version',
        ]);
        $this->assertDatabaseMissing('clients', ['code' => 'CL-OLD-TEMPLATE']);
    }

    public function test_scoped_export_matches_filters_and_never_leaks_projects_or_tasks(): void
    {
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $projectStatus = $this->makeStatus('project', 'xlsx-scope-active', 'in_progress');
        $visibleAlpha = $this->makeProject($manager, $projectStatus);
        $visibleAlpha->update(['code' => 'PRJ-VISIBLE-ALPHA', 'name' => 'Alpha Visible']);
        $visibleBeta = $this->makeProject($manager, $projectStatus);
        $visibleBeta->update(['code' => 'PRJ-VISIBLE-BETA', 'name' => 'Beta Visible']);
        $hiddenAlpha = $this->makeProject($otherManager, $projectStatus);
        $hiddenAlpha->update(['code' => 'PRJ-HIDDEN-ALPHA', 'name' => 'Alpha Hidden']);
        $taskStatus = $this->makeStatus('task', 'xlsx-scope-open', 'open');
        foreach ([
            [$visibleAlpha, 'TSK-VISIBLE-ALPHA', 'Alpha Task'],
            [$visibleBeta, 'TSK-VISIBLE-BETA', 'Beta Task'],
            [$hiddenAlpha, 'TSK-HIDDEN-ALPHA', 'Alpha Hidden Task'],
        ] as [$project, $code, $title]) {
            Task::query()->create([
                'project_id' => $project->id,
                'code' => $code,
                'title' => $title,
                'status_id' => $taskStatus->id,
                'priority' => 'medium',
                'start_at' => now(),
                'due_at' => now()->addDay(),
            ]);
        }

        $projects = $this->actingAs($manager)->get(route('exports.xlsx', [
            'resource' => 'projects',
            'q' => 'Alpha',
        ]));
        $projects->assertOk();
        $projectSheet = $this->loadResponseWorkbook($projects->baseResponse)->getActiveSheet();
        $projectCodes = $projectSheet->rangeToArray('A2:A'.$projectSheet->getHighestDataRow(), null, true, false);
        $this->assertSame([['PRJ-VISIBLE-ALPHA']], $projectCodes);

        $tasks = $this->get(route('exports.xlsx', [
            'resource' => 'tasks',
            'q' => 'Alpha',
        ]));
        $tasks->assertOk();
        $taskSheet = $this->loadResponseWorkbook($tasks->baseResponse)->getActiveSheet();
        $taskCodes = $taskSheet->rangeToArray('B2:B'.$taskSheet->getHighestDataRow(), null, true, false);
        $this->assertSame([['TSK-VISIBLE-ALPHA']], $taskCodes);
        $this->assertDatabaseHas('data_jobs', [
            'type' => 'export',
            'format' => 'xlsx',
            'created_by' => $manager->id,
            'status' => 'succeeded',
        ]);
        $job = DataJob::query()->where('created_by', $manager->id)->latest('id')->firstOrFail();
        $this->assertSame('Alpha', data_get($job->summary, 'filters.q'));
        $this->assertSame($manager->id, data_get($job->summary, 'scope_user_id'));
    }

    public function test_xlsx_row_cap_is_reported_with_sheet_and_row_before_loading_data(): void
    {
        config(['project-desk.data_center.csv_max_rows' => 1]);
        $admin = $this->makeUser();
        $upload = $this->workbookUpload('too-many.xlsx', 'Limited Rows', [
            ['code', 'name', 'email', 'phone', 'address', 'status'],
            ['CL-ONE', 'One', '', '', '', 'active'],
            ['CL-TWO', 'Two', '', '', '', 'active'],
        ]);

        $response = $this->actingAs($admin)->postJson(route('data-center.xlsx.preview', 'clients'), ['file' => $upload]);
        $response->assertCreated()
            ->assertJsonPath('data.status', 'validation_failed')
            ->assertJsonPath('data.summary.can_commit', false);
        $jobId = (int) $response->json('data.id');
        $this->assertDatabaseHas('import_errors', [
            'data_job_id' => $jobId,
            'sheet' => 'Limited Rows',
            'row_number' => 3,
            'code' => 'row_limit',
        ]);
        $this->assertDatabaseMissing('clients', ['code' => 'CL-ONE']);
    }

    public function test_xlsx_zip_expansion_cap_quarantines_the_package(): void
    {
        config(['project-desk.data_center.xlsx_max_uncompressed_megabytes' => 1]);
        $admin = $this->makeUser();
        $upload = $this->workbookUpload('expanded.xlsx', 'Clients', [
            ['code', 'name', 'email', 'phone', 'address', 'status'],
            ['CL-ZIP', 'Zip', '', '', '', 'active'],
        ], [], 2 * 1024 * 1024);

        $response = $this->actingAs($admin)->postJson(route('data-center.xlsx.preview', 'clients'), ['file' => $upload]);
        $response->assertCreated()
            ->assertJsonPath('data.status', 'validation_failed')
            ->assertJsonPath('data.summary.can_commit', false);
        $job = DataJob::query()->findOrFail((int) $response->json('data.id'));
        $this->assertDatabaseHas('import_errors', [
            'data_job_id' => $job->id,
            'code' => 'parse_error',
        ]);
        $this->assertDatabaseHas('file_objects', [
            'id' => $job->file_object_id,
            'scan_status' => 'quarantined',
        ]);
        $this->assertDatabaseMissing('clients', ['code' => 'CL-ZIP']);
    }

    public function test_non_admin_cannot_import_or_export_xlsx(): void
    {
        $manager = $this->makeUser('project_manager');
        $upload = $this->workbookUpload('clients.xlsx', 'Clients', [
            ['code', 'name', 'email', 'phone', 'address', 'status'],
            ['CL-1', 'Client', '', '', '', 'active'],
        ]);

        $this->actingAs($manager)
            ->postJson(route('data-center.xlsx.preview', 'clients'), ['file' => $upload])
            ->assertForbidden();
        $this->get(route('data-center.xlsx.export', 'clients'))->assertForbidden();
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, string>  $formulaCells
     */
    private function workbookUpload(
        string $name,
        string $sheetName,
        array $rows,
        array $formulaCells = [],
        int $extraUncompressedBytes = 0,
        int $templateVersion = 1,
    ): UploadedFile {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCustomProperty('project_desk_template_version', $templateVersion)
            ->setCustomProperty('project_desk_resource', $this->resourceForHeaders($rows[0] ?? []));
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray($rows, null, 'A1');
        foreach ($formulaCells as $coordinate => $formula) {
            $sheet->setCellValue($coordinate, $formula);
        }
        $path = tempnam(sys_get_temp_dir(), 'project-desk-test-xlsx-');
        $this->assertNotFalse($path);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        if ($extraUncompressedBytes > 0) {
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path) === true);
            $this->assertTrue($archive->addFromString(
                'customXml/security-expansion-test.txt',
                str_repeat('A', $extraUncompressedBytes),
            ));
            $archive->close();
        }

        return new UploadedFile(
            $path,
            $name,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    /** @param list<string> $headers */
    private function resourceForHeaders(array $headers): string
    {
        return in_array('project_code', $headers, true) ? 'tasks' : 'clients';
    }

    private function loadResponseWorkbook(mixed $response): Spreadsheet
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response);

        return IOFactory::load($response->getFile()->getPathname());
    }
}
