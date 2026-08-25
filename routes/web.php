<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CsvController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataJobController;
use App\Http\Controllers\ExistingProjectController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\LocalePreferenceController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\OpenNotificationController;
use App\Http\Controllers\PhasePlanController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectFileController;
use App\Http\Controllers\ProjectSummaryPdfController;
use App\Http\Controllers\RequirementAnalysisController;
use App\Http\Controllers\RequirementBookController;
use App\Http\Controllers\RequirementController;
use App\Http\Controllers\RequirementTaxonomyController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SalesDocumentPdfController;
use App\Http\Controllers\ScopedXlsxExportController;
use App\Http\Controllers\SqliteBackupController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TimelineEntryController;
use App\Http\Controllers\XlsxController;
use App\Http\Controllers\XlsxImportController;
use App\Models\DataJob;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::put('locale', LocalePreferenceController::class)->name('locale.update');

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('search', GlobalSearchController::class)->name('search');
    Route::get('exports/xlsx/{resource}', ScopedXlsxExportController::class)->name('exports.xlsx');
    Route::post('notifications/{notification}/open', OpenNotificationController::class)
        ->whereUuid('notification')
        ->name('notifications.open');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::post('projects/existing', [ExistingProjectController::class, 'store'])->name('projects.existing.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('projects/{project}/summary.pdf', ProjectSummaryPdfController::class)->name('projects.summary.pdf');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('tasks/create', [TaskController::class, 'create'])->name('tasks.create');
    Route::get('tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status.update');
    Route::post('tasks/{task}/archive', [TaskController::class, 'archive'])->name('tasks.archive');
    Route::post('tasks/{task}/restore', [TaskController::class, 'restore'])->name('tasks.restore');

    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('clients/create', [ClientController::class, 'create'])->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::post('clients/{client}/archive', [ClientController::class, 'archive'])->name('clients.archive');
    Route::post('clients/{client}/restore', [ClientController::class, 'restore'])->name('clients.restore');
    Route::post('clients/{client}/contacts', [ContactController::class, 'store'])->name('clients.contacts.store');
    Route::put('clients/{client}/contacts/{contact}', [ContactController::class, 'update'])->name('clients.contacts.update');
    Route::post('clients/{client}/contacts/{contact}/archive', [ContactController::class, 'archive'])->name('clients.contacts.archive');
    Route::post('clients/{client}/contacts/{contact}/restore', [ContactController::class, 'restore'])->name('clients.contacts.restore');
    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::post('team', [TeamController::class, 'store'])->name('team.store');
    Route::put('team/{member}', [TeamController::class, 'update'])
        ->middleware('team.sensitive-password')
        ->name('team.update');
    Route::post('team/{member}/archive', [TeamController::class, 'archive'])->name('team.archive');
    Route::post('team/{member}/restore', [TeamController::class, 'restore'])->name('team.restore');
    Route::get('sales', [SalesController::class, 'index'])->name('sales.index');
    Route::post('sales', [SalesController::class, 'store'])->name('sales.store');
    Route::get('sales/{salesDocument}', [SalesController::class, 'show'])->name('sales.show');
    Route::get('sales/{salesDocument}/pdf', SalesDocumentPdfController::class)->name('sales.pdf');
    Route::put('sales/{salesDocument}', [SalesController::class, 'update'])->name('sales.update');
    Route::post('sales/{salesDocument}/archive', [SalesController::class, 'archive'])->name('sales.archive');
    Route::post('sales/{salesDocument}/restore', [SalesController::class, 'restore'])->name('sales.restore');
    Route::post('sales/{salesDocument}/duplicate', [SalesController::class, 'duplicate'])->name('sales.duplicate');
    Route::inertia('data-center', 'data-center/index')
        ->can('viewAny', DataJob::class)
        ->name('data-center.index');
    Route::get('data-center/jobs', [DataJobController::class, 'index'])->name('data-center.jobs.index');
    Route::get('data-center/jobs/{dataJob}', [DataJobController::class, 'show'])->name('data-center.jobs.show');
    Route::get('data-center/csv/{resource}/template', [CsvController::class, 'template'])->name('data-center.csv.template');
    Route::get('data-center/csv/{resource}/export', [CsvController::class, 'export'])->name('data-center.csv.export');
    Route::get('data-center/xlsx/{resource}/template', [XlsxController::class, 'template'])->name('data-center.xlsx.template');
    Route::get('data-center/xlsx/{resource}/export', [XlsxController::class, 'export'])->name('data-center.xlsx.export');
    Route::post('data-center/csv/{resource}/preview', [CsvImportController::class, 'preview'])->name('data-center.csv.preview');
    Route::post('data-center/xlsx/{resource}/preview', [XlsxImportController::class, 'preview'])->name('data-center.xlsx.preview');
    Route::post('data-center/imports/{dataJob}/commit', [CsvImportController::class, 'commit'])->name('data-center.imports.commit');
    Route::post('data-center/backups', [SqliteBackupController::class, 'store'])->name('data-center.backups.store');
    Route::post('data-center/backups/upload', [SqliteBackupController::class, 'upload'])->name('data-center.backups.upload');
    Route::post('data-center/backups/{backup}/validate', [SqliteBackupController::class, 'validateBackup'])
        ->middleware(RequirePassword::using('password.confirm', 900))
        ->name('data-center.backups.validate');
    Route::post('data-center/backups/{backup}/restore', [SqliteBackupController::class, 'restore'])
        ->middleware([RequirePassword::using('password.confirm', 900), 'throttle:backup-restore'])
        ->name('data-center.backups.restore');
    Route::get('data-center/backups/{backup}/download', [SqliteBackupController::class, 'download'])
        ->middleware([RequirePassword::using('password.confirm', 900), 'throttle:backup-download'])
        ->name('data-center.backups.download');
    Route::get('files/{fileObject}/download', [ProjectFileController::class, 'download'])->name('files.download');

    Route::scopeBindings()->group(function () {
        Route::get('projects/{project}/files', [ProjectFileController::class, 'index'])->name('projects.files.index');
        Route::get('projects/{project}/file-targets', [ProjectFileController::class, 'targets'])->name('projects.files.targets');
        Route::post('projects/{project}/files', [ProjectFileController::class, 'store'])
            ->middleware('throttle:project-files')
            ->name('projects.files.store');
        Route::post('projects/{project}/files/{file}/links/{attachmentLink}/archive', [ProjectFileController::class, 'archiveLink'])
            ->withoutScopedBindings()
            ->name('projects.files.links.archive');
        Route::post('projects/{project}/files/{file}/links/{attachmentLink}/restore', [ProjectFileController::class, 'restoreLink'])
            ->withoutScopedBindings()
            ->name('projects.files.links.restore');
        Route::post('projects/{project}/files/{file}/archive', [ProjectFileController::class, 'archive'])
            ->withoutScopedBindings()
            ->name('projects.files.archive');
        Route::post('projects/{project}/files/{file}/restore', [ProjectFileController::class, 'restore'])
            ->withoutScopedBindings()
            ->name('projects.files.restore');
        Route::get('projects/{project}/requirement-book', [RequirementBookController::class, 'show'])->name('projects.requirement-book.show');
        Route::post('projects/{project}/requirement-book/versions', [RequirementBookController::class, 'storeVersion'])->name('projects.requirement-book.versions.store');
        Route::put('projects/{project}/requirement-book/versions/{requirementBookVersion}', [RequirementBookController::class, 'updateVersion'])->name('projects.requirement-book.versions.update');
        Route::post('projects/{project}/requirement-book/versions/{requirementBookVersion}/make-current', [RequirementBookController::class, 'makeCurrent'])->name('projects.requirement-book.versions.current');
        Route::post('projects/{project}/requirement-book/versions/{requirementBookVersion}/archive', [RequirementBookController::class, 'archiveVersion'])->name('projects.requirement-book.versions.archive');
        Route::post('projects/{project}/requirement-book/versions/{requirementBookVersion}/restore', [RequirementBookController::class, 'restoreVersion'])->name('projects.requirement-book.versions.restore');

        Route::get('projects/{project}/requirements', [RequirementController::class, 'index'])->name('projects.requirements.index');
        Route::post('projects/{project}/requirements', [RequirementController::class, 'store'])->name('projects.requirements.store');
        Route::get('projects/{project}/requirements/{requirement}', [RequirementController::class, 'show'])->name('projects.requirements.show');
        Route::put('projects/{project}/requirements/{requirement}', [RequirementController::class, 'update'])->name('projects.requirements.update');
        Route::post('projects/{project}/requirements/{requirement}/archive', [RequirementController::class, 'archive'])->name('projects.requirements.archive');
        Route::post('projects/{project}/requirements/{requirement}/restore', [RequirementController::class, 'restore'])->name('projects.requirements.restore');

        Route::get('projects/{project}/phase-plan', [PhasePlanController::class, 'show'])->name('projects.phase-plan.show');
        Route::put('projects/{project}/phase-plan', [PhasePlanController::class, 'update'])->name('projects.phase-plan.update');

        Route::get('projects/{project}/requirement-taxonomy', [RequirementTaxonomyController::class, 'index'])->name('projects.requirement-taxonomy.index');
        Route::post('projects/{project}/requirement-categories', [RequirementTaxonomyController::class, 'storeCategory'])->name('projects.requirement-categories.store');
        Route::post('projects/{project}/requirement-categories/{category}/groups', [RequirementTaxonomyController::class, 'storeGroup'])->name('projects.requirement-groups.store');
        Route::put('projects/{project}/requirement-categories/{category}', [RequirementTaxonomyController::class, 'updateCategory'])->name('projects.requirement-categories.update');
        Route::put('projects/{project}/requirement-groups/{group}', [RequirementTaxonomyController::class, 'updateGroup'])->name('projects.requirement-groups.update');
        Route::post('projects/{project}/requirement-groups/{group}/merge', [RequirementTaxonomyController::class, 'mergeGroup'])->name('projects.requirement-groups.merge');
        Route::post('projects/{project}/requirements/{requirement}/relations', [RequirementTaxonomyController::class, 'storeRelation'])->name('projects.requirement-relations.store');
        Route::delete('projects/{project}/requirement-relations/{relation}', [RequirementTaxonomyController::class, 'destroyRelation'])->name('projects.requirement-relations.destroy');
        Route::post('projects/{project}/taxonomy-templates/{template}/apply', [RequirementTaxonomyController::class, 'applyTemplate'])
            ->withoutScopedBindings()->name('projects.taxonomy-templates.apply');

        Route::get('projects/{project}/requirement-analyses', [RequirementAnalysisController::class, 'index'])->name('projects.requirement-analyses.index');
        Route::post('projects/{project}/requirement-book/versions/{requirementBookVersion}/analyses', [RequirementAnalysisController::class, 'store'])->name('projects.requirement-analyses.store');
        Route::get('projects/{project}/requirement-analyses/{analysisRun}', [RequirementAnalysisController::class, 'show'])->name('projects.requirement-analyses.show');
        Route::post('projects/{project}/requirement-analyses/{analysisRun}/cancel', [RequirementAnalysisController::class, 'cancel'])->name('projects.requirement-analyses.cancel');
        Route::post('projects/{project}/requirement-analyses/{analysisRun}/retry', [RequirementAnalysisController::class, 'retry'])->name('projects.requirement-analyses.retry');
        Route::post('projects/{project}/requirement-analyses/{analysisRun}/security-override', [RequirementAnalysisController::class, 'override'])->name('projects.requirement-analyses.override');
        Route::get('projects/{project}/requirement-analyses/{analysisRun}/candidates', [RequirementAnalysisController::class, 'candidates'])->name('projects.requirement-candidates.index');
        Route::post('projects/{project}/requirement-analyses/{analysisRun}/decisions', [RequirementAnalysisController::class, 'decide'])->name('projects.requirement-candidates.decide');

        Route::get('projects/{project}/risks', [RiskController::class, 'index'])->name('projects.risks.index');
        Route::post('projects/{project}/risks', [RiskController::class, 'store'])->name('projects.risks.store');
        Route::get('projects/{project}/risks/{risk}', [RiskController::class, 'show'])->name('projects.risks.show');
        Route::put('projects/{project}/risks/{risk}', [RiskController::class, 'update'])->name('projects.risks.update');
        Route::post('projects/{project}/risks/{risk}/archive', [RiskController::class, 'archive'])->name('projects.risks.archive');
        Route::post('projects/{project}/risks/{risk}/restore', [RiskController::class, 'restore'])->name('projects.risks.restore');

        Route::get('projects/{project}/issues', [IssueController::class, 'index'])->name('projects.issues.index');
        Route::post('projects/{project}/issues', [IssueController::class, 'store'])->name('projects.issues.store');
        Route::get('projects/{project}/issues/{issue}', [IssueController::class, 'show'])->name('projects.issues.show');
        Route::put('projects/{project}/issues/{issue}', [IssueController::class, 'update'])->name('projects.issues.update');
        Route::post('projects/{project}/issues/{issue}/archive', [IssueController::class, 'archive'])->name('projects.issues.archive');
        Route::post('projects/{project}/issues/{issue}/restore', [IssueController::class, 'restore'])->name('projects.issues.restore');

        Route::get('projects/{project}/timeline-entries', [TimelineEntryController::class, 'index'])->name('projects.timeline-entries.index');
        Route::post('projects/{project}/timeline-entries', [TimelineEntryController::class, 'store'])->name('projects.timeline-entries.store');
        Route::get('projects/{project}/timeline-entries/{timelineEntry}', [TimelineEntryController::class, 'show'])->name('projects.timeline-entries.show');
        Route::put('projects/{project}/timeline-entries/{timelineEntry}', [TimelineEntryController::class, 'update'])->name('projects.timeline-entries.update');
        Route::post('projects/{project}/timeline-entries/{timelineEntry}/archive', [TimelineEntryController::class, 'archive'])->name('projects.timeline-entries.archive');
        Route::post('projects/{project}/timeline-entries/{timelineEntry}/restore', [TimelineEntryController::class, 'restore'])->name('projects.timeline-entries.restore');

        Route::get('projects/{project}/meetings', [MeetingController::class, 'index'])->name('projects.meetings.index');
        Route::post('projects/{project}/meetings', [MeetingController::class, 'store'])->name('projects.meetings.store');
        Route::get('projects/{project}/meetings/{meeting}', [MeetingController::class, 'show'])->name('projects.meetings.show');
        Route::put('projects/{project}/meetings/{meeting}', [MeetingController::class, 'update'])->name('projects.meetings.update');
        Route::post('projects/{project}/meetings/{meeting}/archive', [MeetingController::class, 'archive'])->name('projects.meetings.archive');
        Route::post('projects/{project}/meetings/{meeting}/restore', [MeetingController::class, 'restore'])->name('projects.meetings.restore');
        Route::put('projects/{project}/meetings/{meeting}/minutes', [MeetingController::class, 'upsertMinutes'])->name('projects.meetings.minutes.upsert');
    });
});

require __DIR__.'/settings.php';
