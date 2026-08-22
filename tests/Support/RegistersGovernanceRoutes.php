<?php

namespace Tests\Support;

use App\Http\Controllers\IssueController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\RequirementController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\TimelineEntryController;
use Illuminate\Support\Facades\Route;

trait RegistersGovernanceRoutes
{
    protected function registerGovernanceRoutes(): void
    {
        if (Route::has('projects.requirements.store')) {
            return;
        }

        Route::middleware('web')->group(function (): void {
            Route::scopeBindings()->group(function (): void {
                Route::get('projects/{project}/requirements', [RequirementController::class, 'index'])->name('projects.requirements.index');
                Route::post('projects/{project}/requirements', [RequirementController::class, 'store'])->name('projects.requirements.store');
                Route::get('projects/{project}/requirements/{requirement}', [RequirementController::class, 'show'])->name('projects.requirements.show');
                Route::put('projects/{project}/requirements/{requirement}', [RequirementController::class, 'update'])->name('projects.requirements.update');
                Route::post('projects/{project}/requirements/{requirement}/archive', [RequirementController::class, 'archive'])->name('projects.requirements.archive');
                Route::post('projects/{project}/requirements/{requirement}/restore', [RequirementController::class, 'restore'])->name('projects.requirements.restore');

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
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}
