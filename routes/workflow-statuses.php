<?php

use App\Http\Controllers\WorkflowStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'active'])
    ->prefix('settings/workflow-statuses')
    ->name('settings.workflow-statuses.')
    ->group(function (): void {
        Route::get('{entityType}', [WorkflowStatusController::class, 'index'])->name('index');
        Route::match(['put', 'patch'], '{entityType}', [WorkflowStatusController::class, 'update'])->name('update');
    });
