<?php

namespace App\Services;

use App\Models\AttachmentLink;
use App\Models\FileObject;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ProjectFileService
{
    /** @var array<string, list<string>> */
    private const ACCEPTED_MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    /** @var array<string, string> */
    private const CANONICAL_MIME_TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly ProjectFileScanner $fileScanner,
    ) {}

    public function storeForProject(
        UploadedFile $upload,
        Project $project,
        User $actor,
        bool $recordActivity = true,
    ): FileObject {
        return $this->storeForTarget($upload, $project, $actor, 'project', null, $recordActivity);
    }

    public function storeForTarget(
        UploadedFile $upload,
        Project $project,
        User $actor,
        string $targetType,
        ?int $targetId,
        bool $recordActivity = true,
    ): FileObject {
        $this->fileScanner->ensureUploadAvailable($project, $actor);
        $this->targetColumns($project, $targetType, $targetId);

        try {
            $inspected = $this->inspect($upload);
        } catch (ValidationException $exception) {
            $this->activityLogger->record(
                $project,
                'project_file.upload_rejected_validation',
                $actor,
                after: ['reason' => 'structural_validation'],
                request: request(),
            );

            throw $exception;
        }

        try {
            $fileObject = Cache::lock('project-file-upload:project:'.$project->id, 30)
                ->block(5, fn (): FileObject => $this->persistWithinQuota(
                    $upload,
                    $project,
                    $actor,
                    $inspected,
                    $targetType,
                    $targetId,
                ));
        } catch (LockTimeoutException) {
            $this->activityLogger->record(
                $project,
                'project_file.upload_rejected_busy',
                $actor,
                after: ['reason' => 'quota_lock_timeout'],
                request: request(),
            );

            throw ValidationException::withMessages([
                'file' => 'تعذر حجز سعة الرفع حالياً. حاول مرة أخرى بعد قليل.',
            ]);
        }

        $fileObject = $this->fileScanner->scan($fileObject, $actor);
        if ($recordActivity) {
            $link = $this->documentLink($fileObject, $project, $targetType, $targetId);
            $this->activityLogger->record(
                $fileObject,
                'project_file.uploaded',
                $actor,
                after: $this->metadata($fileObject, project: $project, attachmentLink: $link),
                request: request(),
            );
        }

        return $fileObject;
    }

    /** @return array{id: int, link_id: int|null, original_name: string, mime_type: string, extension: string|null, size_bytes: int, scan_status: string, uploaded_at: string|null, uploader: array{id: int, name: string}|null, target: array{type: string, id: int, code: string|null, label: string}|null, download_url: string|null, archived_at: string|null, can_archive: bool, can_restore: bool} */
    public function metadata(
        FileObject $file,
        ?User $viewer = null,
        ?Project $project = null,
        ?AttachmentLink $attachmentLink = null,
    ): array {
        $file->loadMissing('uploader:id,name');
        $uploader = $file->uploader;
        $link = $attachmentLink;
        if ($link === null && $project !== null) {
            $link = $file->attachmentLinks()
                ->where('project_id', $project->id)
                ->whereNull('task_id')
                ->whereNull('requirement_id')
                ->whereNull('requirement_book_version_id')
                ->whereNull('meeting_minutes_id')
                ->orderBy('archived_at')
                ->first();
        }
        $link?->loadMissing([
            'project:id,code,name',
            'task:id,project_id,code,title',
            'requirement:id,project_id,code,title',
        ]);
        $isArchived = $link?->archived_at !== null;
        $isDownloadable = ! $isArchived
            && $file->scan_status === 'safe';
        $authorizationArguments = $link === null
            ? [$file, $project]
            : [$file, $project, $link];

        return [
            'id' => $file->id,
            'link_id' => $link?->id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'extension' => $file->extension,
            'size_bytes' => $file->size_bytes,
            'scan_status' => $file->scan_status,
            'uploaded_at' => $file->uploaded_at->toIso8601String(),
            'uploader' => $uploader === null ? null : ['id' => $uploader->id, 'name' => $uploader->name],
            'target' => $link === null ? null : $this->targetMetadata($link),
            'download_url' => $isDownloadable
                ? (Route::has('files.download')
                    ? route('files.download', $file)
                    : url('/files/'.$file->id.'/download'))
                : null,
            'archived_at' => $link?->archived_at?->toIso8601String(),
            'can_archive' => $viewer !== null && $project !== null
                ? $viewer->can('archiveAttachment', $authorizationArguments)
                : false,
            'can_restore' => $viewer !== null && $project !== null
                ? $viewer->can('restoreAttachment', $authorizationArguments)
                : false,
        ];
    }

    public function archiveForProject(FileObject $file, Project $project, User $actor): void
    {
        DB::transaction(function () use ($file, $project, $actor): void {
            $links = AttachmentLink::query()
                ->where('file_object_id', $file->id)
                ->where('project_id', $project->id)
                ->whereNull('task_id')
                ->whereNull('requirement_id')
                ->whereNull('requirement_book_version_id')
                ->whereNull('meeting_minutes_id')
                ->whereNull('archived_at');

            if (! (clone $links)->exists()) {
                abort(404);
            }

            $links->update(['archived_at' => now()]);
            $this->activityLogger->record(
                $file,
                'project_file.archived',
                $actor,
                after: ['project_id' => $project->id, 'file_id' => $file->id],
                request: request(),
            );
        });
    }

    public function restoreForProject(FileObject $file, Project $project, User $actor): void
    {
        DB::transaction(function () use ($file, $project, $actor): void {
            $links = AttachmentLink::query()
                ->where('file_object_id', $file->id)
                ->where('project_id', $project->id)
                ->whereNull('task_id')
                ->whereNull('requirement_id')
                ->whereNull('requirement_book_version_id')
                ->whereNull('meeting_minutes_id')
                ->whereNotNull('archived_at');

            if (! (clone $links)->exists()) {
                abort(404);
            }

            $links->update(['archived_at' => null]);
            $this->activityLogger->record(
                $file,
                'project_file.restored',
                $actor,
                after: ['project_id' => $project->id, 'file_id' => $file->id],
                request: request(),
            );
        });
    }

    public function archiveAttachmentLink(
        FileObject $file,
        Project $project,
        AttachmentLink $attachmentLink,
        User $actor,
    ): void {
        DB::transaction(function () use ($file, $project, $attachmentLink, $actor): void {
            $link = $this->documentLinks($file, $project)
                ->whereKey($attachmentLink->id)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->firstOrFail();
            $link->update(['archived_at' => now()]);
            $this->activityLogger->record(
                $file,
                'project_file.archived',
                $actor,
                after: [
                    'project_id' => $project->id,
                    'file_id' => $file->id,
                    'attachment_link_id' => $link->id,
                    'target' => $this->targetMetadata($link),
                ],
                request: request(),
            );
        });
    }

    public function restoreAttachmentLink(
        FileObject $file,
        Project $project,
        AttachmentLink $attachmentLink,
        User $actor,
    ): void {
        DB::transaction(function () use ($file, $project, $attachmentLink, $actor): void {
            $link = $this->documentLinks($file, $project)
                ->whereKey($attachmentLink->id)
                ->whereNotNull('archived_at')
                ->lockForUpdate()
                ->firstOrFail();
            $link->update(['archived_at' => null]);
            $this->activityLogger->record(
                $file,
                'project_file.restored',
                $actor,
                after: [
                    'project_id' => $project->id,
                    'file_id' => $file->id,
                    'attachment_link_id' => $link->id,
                    'target' => $this->targetMetadata($link),
                ],
                request: request(),
            );
        });
    }

    public function download(FileObject $file): StreamedResponse
    {
        abort_unless($file->scan_status === 'safe', 403);
        $disk = Storage::disk($file->disk);
        if (! $disk->exists($file->storage_key)) {
            abort(404);
        }

        return $disk->download($file->storage_key, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'Content-Length' => (string) $file->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function discardNewUpload(FileObject $file): void
    {
        $hasDurableReference = DB::table('requirement_book_versions')->where('file_object_id', $file->id)->exists()
            || DB::table('meeting_minutes')->where('file_object_id', $file->id)->exists()
            || DB::table('data_jobs')->where('file_object_id', $file->id)->exists();

        if ($hasDurableReference) {
            return;
        }

        $disk = $file->disk;
        $storageKey = $file->storage_key;
        DB::transaction(function () use ($file): void {
            AttachmentLink::query()->where('file_object_id', $file->id)->delete();
            FileObject::query()->whereKey($file->id)->delete();
        });
        Storage::disk($disk)->delete($storageKey);
    }

    /** @return array{task_id: int|null, requirement_id: int|null} */
    private function targetColumns(
        Project $project,
        string $targetType,
        ?int $targetId,
        bool $lock = false,
    ): array {
        if ($targetType === 'project' && $targetId === null) {
            return ['task_id' => null, 'requirement_id' => null];
        }

        $targetExists = match ($targetType) {
            'task' => $targetId !== null && Task::query()
                ->whereKey($targetId)
                ->where('project_id', $project->id)
                ->whereNull('archived_at')
                ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
                ->exists(),
            'requirement' => $targetId !== null && Requirement::query()
                ->whereKey($targetId)
                ->where('project_id', $project->id)
                ->whereNull('archived_at')
                ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
                ->exists(),
            default => false,
        };

        if (! $targetExists) {
            throw ValidationException::withMessages([
                'target_id' => 'The selected attachment target is unavailable in this project.',
            ]);
        }

        return [
            'task_id' => $targetType === 'task' ? $targetId : null,
            'requirement_id' => $targetType === 'requirement' ? $targetId : null,
        ];
    }

    /** @return Builder<AttachmentLink> */
    private function documentLinks(FileObject $file, Project $project): Builder
    {
        return AttachmentLink::query()
            ->where('file_object_id', $file->id)
            ->where('project_id', $project->id)
            ->whereNull('requirement_book_version_id')
            ->whereNull('meeting_minutes_id')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $target): void {
                    $target->whereNull('task_id')->whereNull('requirement_id');
                })->orWhere(function (Builder $target): void {
                    $target->whereNotNull('task_id')->whereNull('requirement_id');
                })->orWhere(function (Builder $target): void {
                    $target->whereNull('task_id')->whereNotNull('requirement_id');
                });
            });
    }

    private function documentLink(
        FileObject $file,
        Project $project,
        string $targetType,
        ?int $targetId,
    ): AttachmentLink {
        $query = $this->documentLinks($file, $project);
        match ($targetType) {
            'project' => $query->whereNull('task_id')->whereNull('requirement_id'),
            'task' => $query->where('task_id', $targetId)->whereNull('requirement_id'),
            'requirement' => $query->whereNull('task_id')->where('requirement_id', $targetId),
            default => throw ValidationException::withMessages(['target_type' => 'Invalid attachment target type.']),
        };

        return $query->firstOrFail();
    }

    /** @return array{type: string, id: int, code: string|null, label: string} */
    private function targetMetadata(AttachmentLink $link): array
    {
        $link->loadMissing([
            'project:id,code,name',
            'task:id,project_id,code,title',
            'requirement:id,project_id,code,title',
        ]);

        if ($link->task_id !== null && $link->task instanceof Task) {
            return [
                'type' => 'task',
                'id' => $link->task_id,
                'code' => $link->task->code,
                'label' => $link->task->title,
            ];
        }

        if ($link->requirement_id !== null && $link->requirement instanceof Requirement) {
            return [
                'type' => 'requirement',
                'id' => $link->requirement_id,
                'code' => $link->requirement->code,
                'label' => $link->requirement->title,
            ];
        }

        return [
            'type' => 'project',
            'id' => (int) $link->project_id,
            'code' => $link->project->code,
            'label' => $link->project->name,
        ];
    }

    /** @return array{extension: string, mime_type: string, size_bytes: int} */
    private function inspect(UploadedFile $upload): array
    {
        if (! $upload->isValid()) {
            throw ValidationException::withMessages(['file' => 'لم يكتمل رفع الملف بصورة صحيحة.']);
        }

        $extension = Str::lower($upload->getClientOriginalExtension());
        if (! array_key_exists($extension, self::ACCEPTED_MIME_TYPES)) {
            throw ValidationException::withMessages(['file' => 'امتداد الملف غير مسموح.']);
        }

        $size = $upload->getSize();
        $maxBytes = (int) config('project-desk.uploads.max_file_kilobytes', 25 * 1024) * 1024;
        if (! is_int($size) || $size < 1 || $size > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'حجم الملف غير صالح أو يتجاوز الحد المسموح.']);
        }

        $detectedMime = $upload->getMimeType();
        if (! is_string($detectedMime) || ! in_array($detectedMime, self::ACCEPTED_MIME_TYPES[$extension], true)) {
            throw ValidationException::withMessages(['file' => 'محتوى الملف لا يطابق امتداده.']);
        }

        $this->validateContent($upload->getPathname(), $extension);
        $canonicalMime = self::CANONICAL_MIME_TYPES[$extension];
        $configuredMimeTypes = config('project-desk.uploads.allowed_mime_types', []);
        if (! is_array($configuredMimeTypes) || ! in_array($canonicalMime, $configuredMimeTypes, true)) {
            throw ValidationException::withMessages(['file' => 'نوع الملف غير مفعل في إعدادات النظام.']);
        }

        return ['extension' => $extension, 'mime_type' => $canonicalMime, 'size_bytes' => $size];
    }

    /** @param array{extension: string, mime_type: string, size_bytes: int} $inspected */
    private function persistWithinQuota(
        UploadedFile $upload,
        Project $project,
        User $actor,
        array $inspected,
        string $targetType,
        ?int $targetId,
    ): FileObject {
        $this->assertQuota($project, $actor, $inspected['size_bytes']);
        $disk = (string) config('project-desk.uploads.disk', 'local');
        $directory = 'projects/'.$project->id.'/'.now()->format('Y/m');
        $storageName = Str::uuid().'.'.$inspected['extension'];
        $checksum = hash_file('sha256', $upload->getPathname());
        if (! is_string($checksum)) {
            throw new RuntimeException('تعذر حساب بصمة الملف.');
        }

        $storageKey = Storage::disk($disk)->putFileAs($directory, $upload, $storageName);
        if (! is_string($storageKey) || $storageKey === '') {
            throw ValidationException::withMessages(['file' => 'تعذر حفظ الملف في التخزين الخاص.']);
        }

        try {
            return DB::transaction(function () use ($upload, $project, $actor, $inspected, $disk, $storageKey, $checksum, $targetType, $targetId): FileObject {
                $targetColumns = $this->targetColumns($project, $targetType, $targetId, true);
                $file = FileObject::query()->create([
                    'disk' => $disk,
                    'storage_key' => $storageKey,
                    'original_name' => $this->safeOriginalName($upload, $inspected['extension']),
                    'mime_type' => $inspected['mime_type'],
                    'extension' => $inspected['extension'],
                    'size_bytes' => $inspected['size_bytes'],
                    'checksum_sha256' => $checksum,
                    'scan_status' => 'structurally_safe',
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                ]);

                AttachmentLink::query()->create([
                    'file_object_id' => $file->id,
                    'project_id' => $project->id,
                    ...$targetColumns,
                ]);

                return $file->load('uploader:id,name');
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($storageKey);

            throw $exception;
        }
    }

    private function assertQuota(Project $project, User $actor, int $requestedBytes): void
    {
        $projectFiles = FileObject::query()->whereHas(
            'attachmentLinks',
            fn ($query) => $query->where('project_id', $project->id),
        );
        $projectBytes = (int) (clone $projectFiles)->sum('size_bytes');
        $projectCount = (int) (clone $projectFiles)->count();
        $userBytes = (int) (clone $projectFiles)->where('uploaded_by', $actor->id)->sum('size_bytes');
        $projectLimit = (int) config('project-desk.uploads.project_quota_bytes', 10 * 1024 * 1024 * 1024);
        $userLimit = (int) config('project-desk.uploads.user_project_quota_bytes', 2 * 1024 * 1024 * 1024);
        $fileLimit = (int) config('project-desk.uploads.project_file_limit', 10000);

        $reason = match (true) {
            $projectCount + 1 > $fileLimit => 'project_file_count',
            $projectBytes + $requestedBytes > $projectLimit => 'project_bytes',
            $userBytes + $requestedBytes > $userLimit => 'user_project_bytes',
            default => null,
        };
        if ($reason === null) {
            return;
        }

        $this->activityLogger->record(
            $project,
            'project_file.upload_rejected_quota',
            $actor,
            after: ['reason' => $reason, 'requested_bytes' => $requestedBytes],
            request: request(),
        );

        throw ValidationException::withMessages([
            'file' => 'تجاوز الرفع الحصة التخزينية المسموح بها للمشروع أو للمستخدم.',
        ]);
    }

    private function validateContent(string $path, string $extension): void
    {
        if ($extension === 'pdf') {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw ValidationException::withMessages(['file' => 'تعذر فحص الملف.']);
            }
            $header = fread($handle, 5);
            fclose($handle);
            if ($header !== '%PDF-') {
                throw ValidationException::withMessages(['file' => 'توقيع ملف PDF غير صالح.']);
            }

            $contents = file_get_contents($path);
            if (! is_string($contents)) {
                throw ValidationException::withMessages(['file' => 'تعذر فحص ملف PDF.']);
            }
            foreach (['/JavaScript', '/JS', '/Launch', '/EmbeddedFile'] as $dangerousToken) {
                if (stripos($contents, $dangerousToken) !== false) {
                    throw ValidationException::withMessages(['file' => 'يحتوي ملف PDF على محتوى نشط غير مسموح.']);
                }
            }

            return;
        }

        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $this->validateOpenXmlPackage($path, $extension);

            return;
        }

        if ($extension === 'csv') {
            $sample = file_get_contents($path, false, null, 0, 65536);
            if (! is_string($sample) || str_contains($sample, "\0")) {
                throw ValidationException::withMessages(['file' => 'محتوى CSV غير صالح.']);
            }

            return;
        }

        $image = getimagesize($path);
        $expectedMime = self::CANONICAL_MIME_TYPES[$extension];
        if (! is_array($image) || $image['mime'] !== $expectedMime) {
            throw ValidationException::withMessages(['file' => 'بيانات الصورة غير صالحة.']);
        }
    }

    private function validateOpenXmlPackage(string $path, string $extension): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['file' => 'حزمة Office غير صالحة.']);
        }

        $requiredEntry = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
        $valid = $zip->locateName('[Content_Types].xml') !== false
            && $zip->locateName($requiredEntry) !== false;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);
            if (is_string($entry) && preg_match('/(^|\/)vbaProject\.bin$/i', $entry) === 1) {
                $valid = false;
                break;
            }
        }
        $zip->close();

        if (! $valid) {
            throw ValidationException::withMessages(['file' => 'حزمة Office غير مكتملة أو تحتوي وحدات ماكرو غير مسموحة.']);
        }
    }

    private function safeOriginalName(UploadedFile $upload, string $extension): string
    {
        $name = trim(str_replace(['/', '\\'], '-', $upload->getClientOriginalName()));
        $cleaned = preg_replace('/[\x00-\x1F\x7F\p{Cf}]/u', '', $name);
        if (! is_string($cleaned) || $cleaned === '') {
            $cleaned = 'document.'.$extension;
        }

        $cleaned = ltrim($cleaned, '. ');
        if ($cleaned === '') {
            $cleaned = 'document.'.$extension;
        }

        return mb_substr($cleaned, 0, 240);
    }
}
