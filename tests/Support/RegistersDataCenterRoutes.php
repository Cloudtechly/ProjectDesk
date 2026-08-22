<?php

namespace Tests\Support;

use App\Http\Controllers\CsvController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DataJobController;
use App\Http\Controllers\SqliteBackupController;
use App\Http\Controllers\XlsxController;
use App\Http\Controllers\XlsxImportController;
use Illuminate\Support\Facades\Route;

trait RegistersDataCenterRoutes
{
    protected function registerDataCenterRoutes(): void
    {
        if (Route::has('data-center.jobs.show')) {
            if (! Route::has('data-center.backups.upload')) {
                Route::middleware('web')->post(
                    '_tests/data-center/backups/upload',
                    [SqliteBackupController::class, 'upload'],
                )->name('data-center.backups.upload');
                Route::getRoutes()->refreshNameLookups();
                Route::getRoutes()->refreshActionLookups();
            }

            return;
        }

        Route::middleware('web')->group(function (): void {
            Route::get('_tests/data-center/jobs', [DataJobController::class, 'index'])->name('data-center.jobs.index');
            Route::get('_tests/data-center/jobs/{dataJob}', [DataJobController::class, 'show'])->name('data-center.jobs.show');
            Route::get('_tests/data-center/csv/{resource}/template', [CsvController::class, 'template'])->name('data-center.csv.template');
            Route::get('_tests/data-center/csv/{resource}/export', [CsvController::class, 'export'])->name('data-center.csv.export');
            Route::post('_tests/data-center/csv/{resource}/preview', [CsvImportController::class, 'preview'])->name('data-center.csv.preview');
            Route::get('_tests/data-center/xlsx/{resource}/template', [XlsxController::class, 'template'])->name('data-center.xlsx.template');
            Route::get('_tests/data-center/xlsx/{resource}/export', [XlsxController::class, 'export'])->name('data-center.xlsx.export');
            Route::post('_tests/data-center/xlsx/{resource}/preview', [XlsxImportController::class, 'preview'])->name('data-center.xlsx.preview');
            Route::post('_tests/data-center/imports/{dataJob}/commit', [CsvImportController::class, 'commit'])->name('data-center.imports.commit');
            Route::post('_tests/data-center/backups', [SqliteBackupController::class, 'store'])->name('data-center.backups.store');
            Route::post('_tests/data-center/backups/upload', [SqliteBackupController::class, 'upload'])->name('data-center.backups.upload');
            Route::post('_tests/data-center/backups/{backup}/validate', [SqliteBackupController::class, 'validateBackup'])->name('data-center.backups.validate');
            Route::post('_tests/data-center/backups/{backup}/restore', [SqliteBackupController::class, 'restore'])->name('data-center.backups.restore');
            Route::get('_tests/data-center/backups/{backup}/download', [SqliteBackupController::class, 'download'])->name('data-center.backups.download');
        });
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}
