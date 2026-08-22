<?php

namespace App\Services;

use App\Contracts\MalwareScanner;
use App\Models\FileObject;
use App\Models\Project;
use App\Models\User;
use App\Security\MalwareScanResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProjectFileScanner
{
    public function __construct(
        private readonly MalwareScanner $scanner,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function ensureUploadAvailable(Project $project, User $actor): void
    {
        if (! app()->isProduction() || $this->scanner->configured()) {
            return;
        }

        $this->activityLogger->record(
            $project,
            'project_file.upload_rejected_scanner_unavailable',
            $actor,
            after: ['reason' => 'scanner_not_configured'],
            request: request(),
        );

        throw ValidationException::withMessages([
            'file' => 'رفع الملفات غير متاح حتى يتم تهيئة ماسح البرمجيات الخبيثة.',
        ]);
    }

    public function scan(FileObject $file, User $actor): FileObject
    {
        if (! $this->scanner->configured()) {
            return $file;
        }

        try {
            $path = Storage::disk($file->disk)->path($file->storage_key);
            $result = $this->scanner->scan($path);
        } catch (Throwable) {
            $result = MalwareScanResult::failed('Scanner integration failed.');
        }

        if ($result->status === 'clean') {
            $file->update(['scan_status' => 'safe']);
            $this->activityLogger->record(
                $file,
                'project_file.scan_clean',
                $actor,
                after: ['scan_status' => 'safe'],
                request: request(),
            );

            return $file->refresh();
        }

        if ($result->status === 'infected') {
            $file->update(['scan_status' => 'quarantined']);
            $this->activityLogger->record(
                $file,
                'project_file.malware_rejected',
                $actor,
                after: [
                    'scan_status' => 'quarantined',
                    'signature' => $result->signature === null ? null : mb_substr($result->signature, 0, 160),
                ],
                request: request(),
            );

            throw ValidationException::withMessages([
                'file' => 'رُفض الملف بعد اكتشاف محتوى ضار ووُضع في الحجر.',
            ]);
        }

        $file->update(['scan_status' => 'structurally_safe']);
        $this->activityLogger->record(
            $file,
            'project_file.scan_failed',
            $actor,
            after: [
                'scan_status' => 'structurally_safe',
                'reason' => $result->message === null ? 'scanner_failure' : mb_substr($result->message, 0, 160),
            ],
            request: request(),
        );

        return $file->refresh();
    }
}
