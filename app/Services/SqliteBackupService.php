<?php

namespace App\Services;

use App\Models\DataJob;
use App\Models\FileObject;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SqliteBackupService
{
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'migrations',
        'users',
        'clients',
        'projects',
        'tasks',
        'workflow_statuses',
        'file_objects',
        'data_jobs',
        'activity_logs',
        'system_settings',
    ];

    public function __construct(
        private readonly SqliteBackupManager $manager,
        private readonly ProjectBackupBundleManager $bundleManager,
        private readonly ActivityLogger $activityLogger,
        private readonly UserSessionSecurity $sessionSecurity,
        private readonly FileInventoryLock $fileInventoryLock,
        private readonly BackupFileRestoreTransaction $fileRestoreTransaction,
    ) {}

    public function ensureSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw ValidationException::withMessages(['database' => 'النسخ والاستعادة المباشرة متاحان لقاعدة SQLite فقط.']);
        }
    }

    /** @param array<string, bool|int|string|null> $context */
    public function create(User $actor, string $purpose = 'manual', array $context = []): DataJob
    {
        return $this->fileInventoryLock->block(
            fn (): DataJob => $this->createWithStableFileInventory($actor, $purpose, $context),
            60,
        );
    }

    /** @param array<string, bool|int|string|null> $context */
    private function createWithStableFileInventory(User $actor, string $purpose, array $context): DataJob
    {
        $this->ensureSqlite();
        $databasePath = $this->databasePath();
        $disk = (string) config('project-desk.data_center.backup_disk', 'local');
        $directory = trim((string) config('project-desk.data_center.backup_directory', 'backups/project-desk'), '/');
        $key = $directory.'/project-desk-'.now()->format('Ymd-His-u').'-'.Str::lower(Str::random(8)).'.pdesk';
        $destination = Storage::disk($disk)->path($key);
        $summary = [...$context, 'purpose' => $purpose];
        $job = DataJob::query()->create([
            'type' => 'backup',
            'resource_type' => 'system',
            'format' => 'pdesk',
            'status' => 'processing',
            'created_by' => $actor->id,
            'summary' => $summary,
            'started_at' => now(),
        ]);

        try {
            $temporaryDirectory = $this->temporaryDirectory('project-desk-backup-snapshot-');
            try {
                $snapshotPath = $temporaryDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
                $database = $this->manager->snapshot($databasePath, $snapshotPath, self::REQUIRED_TABLES);
                $database['schema_fingerprint'] = $this->manager->schemaFingerprint($snapshotPath);
                $bundle = $this->bundleManager->create($snapshotPath, $destination);
                $result = [
                    ...$database,
                    ...$bundle,
                    'quick_check' => 'ok',
                    'tables' => $database['tables'],
                ];
            } finally {
                $this->removeTemporaryDirectory($temporaryDirectory);
            }
            $file = FileObject::query()->create([
                'disk' => $disk,
                'storage_key' => $key,
                'original_name' => basename($key),
                'mime_type' => 'application/vnd.projectdesk.backup',
                'extension' => 'pdesk',
                'size_bytes' => $result['size_bytes'],
                'checksum_sha256' => $result['checksum_sha256'],
                'scan_status' => 'safe',
                'uploaded_by' => $actor->id,
                'uploaded_at' => now(),
            ]);
            $job->update([
                'status' => 'succeeded',
                'file_object_id' => $file->id,
                'summary' => [...$summary, ...$result],
                'completed_at' => now(),
            ]);
            $this->activityLogger->record($job, 'database_backup.created', $actor, after: $job->fresh()->toArray(), request: request());
        } catch (Throwable $exception) {
            if (Storage::disk($disk)->exists($key)) {
                Storage::disk($disk)->delete($key);
            }
            $job->update(['status' => 'failed', 'error_message' => $exception->getMessage(), 'completed_at' => now()]);
            throw ValidationException::withMessages(['database' => 'فشل إنشاء نسخة SQLite أو التحقق منها.']);
        }

        return $job->fresh()->load('fileObject');
    }

    public function pruneAutomaticBackups(int $keep): int
    {
        $keep = max(1, min(90, $keep));
        $query = DataJob::query()
            ->where('type', 'backup')
            ->whereIn('resource_type', ['database', 'system'])
            ->where('status', 'succeeded')
            ->where('summary->purpose', 'automatic')
            ->whereNotNull('file_object_id')
            ->orderByDesc('completed_at')
            ->orderByDesc('id');
        $keepIds = (clone $query)->limit($keep)->pluck('id');
        $jobs = $query
            ->whereNotIn('id', $keepIds)
            ->with('fileObject')
            ->get();
        $pruned = 0;

        foreach ($jobs as $job) {
            $file = $job->fileObject;
            if (! $file instanceof FileObject
                || $file->attachmentLinks()->exists()
                || $file->dataJobs()->where('data_jobs.id', '!=', $job->id)->exists()) {
                continue;
            }

            if (Storage::disk($file->disk)->exists($file->storage_key)) {
                Storage::disk($file->disk)->delete($file->storage_key);
            }
            $file->delete();
            $job->update([
                'summary' => [
                    ...($job->summary ?? []),
                    'retention_pruned' => true,
                    'retention_pruned_at' => now()->toIso8601String(),
                ],
            ]);
            $pruned++;
        }

        return $pruned;
    }

    public function upload(UploadedFile $uploadedFile, User $actor): DataJob
    {
        $this->ensureSqlite();
        $disk = (string) config('project-desk.data_center.backup_disk', 'local');
        $directory = trim((string) config('project-desk.data_center.backup_directory', 'backups/project-desk'), '/').'/uploads';
        $key = $directory.'/uploaded-'.Str::lower((string) Str::uuid()).'.pdesk';
        $job = DataJob::query()->create([
            'type' => 'backup_upload',
            'resource_type' => 'system',
            'format' => 'pdesk',
            'status' => 'processing',
            'created_by' => $actor->id,
            'summary' => ['source' => 'external_upload'],
            'started_at' => now(),
        ]);

        $temporaryDirectory = '';
        try {
            $sourcePath = $uploadedFile->getRealPath();
            $size = $uploadedFile->getSize();
            $extension = Str::lower($uploadedFile->getClientOriginalExtension());
            $mimeType = $uploadedFile->getMimeType();
            $maxBytes = (int) config('project-desk.data_center.backup_max_kilobytes', 512 * 1024) * 1024;
            if (! $uploadedFile->isValid()
                || ! is_string($sourcePath)
                || ! is_int($size)
                || $size <= 0
                || $size > $maxBytes
                || ! in_array($extension, ['pdesk', 'sqlite', 'sqlite3', 'db'], true)
                || ! is_string($mimeType)
                || ! in_array($mimeType, [
                    'application/vnd.projectdesk.backup',
                    'application/vnd.sqlite3',
                    'application/x-sqlite3',
                    'application/x-sqlite',
                    'application/octet-stream',
                    'application/zip',
                ], true)) {
                throw new RuntimeException('The uploaded file metadata is not valid for a Project Desk backup.');
            }

            $temporaryDirectory = $this->temporaryDirectory('project-desk-backup-upload-');
            if ($this->bundleManager->isBundle($sourcePath)) {
                if ($extension !== 'pdesk') {
                    throw new RuntimeException('Encrypted Project Desk backups must use the .pdesk extension.');
                }
                $prepared = $this->bundleManager->prepare(
                    $sourcePath,
                    $temporaryDirectory.DIRECTORY_SEPARATOR.'source',
                );
                $databasePath = $prepared['database_path'];
                $databaseValidation = $this->manager->validate($databasePath, self::REQUIRED_TABLES);
                $databaseValidation['schema_fingerprint'] = $this->compatibleSchemaFingerprint($databasePath);
                $containsCurrentAdmin = $this->manager->containsActiveAdmin($databasePath, $actor->id);
                $manifest = $prepared['manifest'];
                $storedSourcePath = $sourcePath;
                $storedValidation = [
                    ...$databaseValidation,
                    'size_bytes' => $prepared['size_bytes'],
                    'checksum_sha256' => $prepared['checksum_sha256'],
                    'database_checksum_sha256' => $manifest['database']['checksum_sha256'],
                    'database_size_bytes' => $manifest['database']['size_bytes'],
                    'bundle_format' => $manifest['format'],
                    'bundle_version' => $manifest['version'],
                    'encrypted' => true,
                    'cipher' => $prepared['encryption']['cipher'],
                    'key_id' => $prepared['encryption']['key_id'],
                    'files_complete' => $manifest['files_complete'],
                    'legacy_database_only' => $manifest['legacy_database_only'],
                    'files_count' => count($manifest['files']),
                    'files_size_bytes' => array_sum(array_column($manifest['files'], 'size_bytes')),
                ];
            } else {
                $header = file_get_contents($sourcePath, false, null, 0, 16);
                if (! is_string($header) || ! hash_equals("SQLite format 3\0", $header)) {
                    throw new RuntimeException('The uploaded file is neither an encrypted bundle nor SQLite.');
                }
                $databaseValidation = $this->manager->validate($sourcePath, self::REQUIRED_TABLES);
                $databaseValidation['schema_fingerprint'] = $this->compatibleSchemaFingerprint($sourcePath);
                $containsCurrentAdmin = $this->manager->containsActiveAdmin($sourcePath, $actor->id);
                $storedSourcePath = $temporaryDirectory.DIRECTORY_SEPARATOR.'legacy-converted.pdesk';
                $bundle = $this->bundleManager->create($sourcePath, $storedSourcePath, false);
                $storedValidation = [
                    ...$databaseValidation,
                    ...$bundle,
                    'legacy_database_only' => true,
                    'quick_check' => 'ok',
                    'tables' => $databaseValidation['tables'],
                ];
            }

            $sourceStream = fopen($storedSourcePath, 'rb');
            if (! is_resource($sourceStream)) {
                throw new RuntimeException('The verified backup could not be opened for private storage.');
            }
            try {
                $stored = Storage::disk($disk)->put($key, $sourceStream);
            } finally {
                fclose($sourceStream);
            }
            if (! $stored) {
                throw new RuntimeException('The uploaded backup could not be stored.');
            }
            $storedPath = Storage::disk($disk)->path($key);
            $storedChecksum = hash_file('sha256', $storedPath);
            if (! is_string($storedChecksum)
                || ! hash_equals((string) $storedValidation['checksum_sha256'], $storedChecksum)) {
                throw new RuntimeException('The stored backup checksum differs from the uploaded file.');
            }
            $this->bundleManager->prepare(
                $storedPath,
                $temporaryDirectory.DIRECTORY_SEPARATOR.'stored',
            );
            $originalName = basename(str_replace('\\', '/', $uploadedFile->getClientOriginalName()));
            $originalStem = pathinfo(
                preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'uploaded-backup',
                PATHINFO_FILENAME,
            );
            $originalName = Str::limit($originalStem, 230, '').'.pdesk';

            $result = DB::transaction(function () use (
                $actor,
                $containsCurrentAdmin,
                $disk,
                $job,
                $key,
                $originalName,
                $storedValidation,
            ): DataJob {
                $file = FileObject::query()->create([
                    'disk' => $disk,
                    'storage_key' => $key,
                    'original_name' => $originalName,
                    'mime_type' => 'application/vnd.projectdesk.backup',
                    'extension' => 'pdesk',
                    'size_bytes' => $storedValidation['size_bytes'],
                    'checksum_sha256' => $storedValidation['checksum_sha256'],
                    'scan_status' => 'safe',
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                ]);
                $job->update([
                    'status' => 'succeeded',
                    'file_object_id' => $file->id,
                    'summary' => [
                        'source' => 'external_upload',
                        ...$storedValidation,
                        'contains_current_admin' => $containsCurrentAdmin,
                    ],
                    'completed_at' => now(),
                ]);
                $this->activityLogger->record(
                    $file,
                    'database_backup.uploaded',
                    $actor,
                    after: $job->fresh()->toArray(),
                    request: request(),
                );

                return $job->fresh()->load('fileObject');
            });
        } catch (Throwable $exception) {
            if (Storage::disk($disk)->exists($key)) {
                Storage::disk($disk)->delete($key);
            }
            $job->update([
                'status' => 'failed',
                'error_message' => 'The uploaded backup failed SQLite integrity validation.',
                'completed_at' => now(),
            ]);
            throw ValidationException::withMessages([
                'file' => 'ملف النسخة غير صالح أو لا يطابق بنية Project Desk المطلوبة.',
            ]);
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }

        return $result;
    }

    /** @return array<string, bool|int|string|array<int, string>> */
    public function validate(FileObject $file, User $actor): array
    {
        $this->ensureSqlite();
        $path = $this->verifiedPath($file);
        if ($this->bundleManager->isBundle($path)) {
            $temporaryDirectory = $this->temporaryDirectory('project-desk-backup-validate-');
            try {
                try {
                    $prepared = $this->bundleManager->prepare($path, $temporaryDirectory);
                } catch (Throwable $exception) {
                    report($exception);
                    throw ValidationException::withMessages([
                        'backup' => 'تعذر فك النسخة المشفرة أو فشل فحص سلامتها ومحتواها.',
                    ]);
                }
                if (! hash_equals($file->checksum_sha256, $prepared['checksum_sha256'])) {
                    throw ValidationException::withMessages([
                        'backup' => 'بصمة ملف النسخة المشفرة لا تطابق السجل المحفوظ.',
                    ]);
                }
                $database = $this->manager->validate($prepared['database_path'], self::REQUIRED_TABLES);
                $manifest = $prepared['manifest'];
                $result = [
                    ...$database,
                    'size_bytes' => $prepared['size_bytes'],
                    'checksum_sha256' => $prepared['checksum_sha256'],
                    'database_checksum_sha256' => $manifest['database']['checksum_sha256'],
                    'database_size_bytes' => $manifest['database']['size_bytes'],
                    'schema_fingerprint' => $this->compatibleSchemaFingerprint($prepared['database_path']),
                    'schema_compatible' => true,
                    'contains_current_admin' => $this->manager->containsActiveAdmin($prepared['database_path'], $actor->id),
                    'bundle_format' => $manifest['format'],
                    'bundle_version' => $manifest['version'],
                    'encrypted' => true,
                    'cipher' => $prepared['encryption']['cipher'],
                    'key_id' => $prepared['encryption']['key_id'],
                    'files_complete' => $manifest['files_complete'],
                    'legacy_database_only' => $manifest['legacy_database_only'],
                    'files_count' => count($manifest['files']),
                    'files_size_bytes' => array_sum(array_column($manifest['files'], 'size_bytes')),
                ];
            } finally {
                $this->removeTemporaryDirectory($temporaryDirectory);
            }
        } else {
            $result = $this->manager->validate($path, self::REQUIRED_TABLES);
            $result['schema_fingerprint'] = $this->compatibleSchemaFingerprint($path);
            $result['schema_compatible'] = true;
            $result['contains_current_admin'] = $this->manager->containsActiveAdmin($path, $actor->id);
            $result['encrypted'] = false;
            $result['files_complete'] = false;
            $result['legacy_database_only'] = true;
            $result['files_count'] = 0;
            $result['files_size_bytes'] = 0;
        }
        $this->activityLogger->record($file, 'database_backup.validated', $actor, after: $result, request: request());

        return $result;
    }

    /**
     * @return array{restored_checksum_sha256: string, restored_database_checksum_sha256: string, pre_restore_backup_id: int, restored_at: string, files_restored: int}
     */
    public function restore(FileObject $source, string $expectedChecksum, User $actor): array
    {
        $this->ensureSqlite();
        $sourcePath = $this->verifiedPath($source);
        if ($this->bundleManager->isBundle($sourcePath)) {
            return $this->restoreBundle($source, $sourcePath, $expectedChecksum, $actor);
        }

        $validated = $this->manager->validate($sourcePath, self::REQUIRED_TABLES);
        $this->compatibleSchemaFingerprint($sourcePath);
        if (! hash_equals($source->checksum_sha256, $validated['checksum_sha256'])
            || ! hash_equals($expectedChecksum, $validated['checksum_sha256'])) {
            throw ValidationException::withMessages(['checksum_sha256' => 'بصمة النسخة لا تطابق الملف المحدد.']);
        }
        if (! $this->manager->containsActiveAdmin($sourcePath, $actor->id)) {
            throw ValidationException::withMessages(['database' => 'النسخة لا تحتوي حساب المدير الحالي بحالة نشطة، لذا لن تتم الاستعادة.']);
        }

        $preRestore = $this->create($actor, 'pre_restore');
        $preRestoreFile = $preRestore->fileObject;
        if (! $preRestoreFile instanceof FileObject) {
            throw ValidationException::withMessages(['database' => 'تعذر تثبيت مرجع نسخة ما قبل الاستعادة.']);
        }
        $sourceMetadata = $this->fileMetadata($source);
        $preRestoreMetadata = $this->fileMetadata($preRestoreFile);
        $preRestorePath = Storage::disk($preRestoreFile->disk)->path($preRestoreFile->storage_key);
        $targetPath = $this->databasePath();
        try {
            DB::disconnect();
            $result = $this->manager->restore($sourcePath, $targetPath, self::REQUIRED_TABLES);
        } catch (Throwable $exception) {
            DB::purge();
            DB::reconnect();
            report($exception);
            throw ValidationException::withMessages(['database' => 'فشلت الاستعادة وبقيت قاعدة البيانات السابقة متاحة.']);
        }
        DB::purge();
        DB::reconnect();

        $completedAt = now()->toIso8601String();
        try {
            $preRestoreJobId = DB::transaction(function () use (
                $actor,
                $completedAt,
                $preRestore,
                $preRestoreMetadata,
                $result,
                $sourceMetadata,
            ): int {
                DataJob::query()->where('status', 'processing')->update([
                    'status' => 'failed',
                    'error_message' => 'The job was interrupted by a database restore.',
                    'completed_at' => now(),
                ]);
                $restoredSource = $this->restoreFileRecord($sourceMetadata, $actor);
                $restoredPreRestore = $this->restoreFileRecord($preRestoreMetadata, $actor);
                $recoveredPreRestore = DataJob::query()->create([
                    'type' => 'backup',
                    'resource_type' => 'database',
                    'format' => 'sqlite',
                    'status' => 'succeeded',
                    'file_object_id' => $restoredPreRestore->id,
                    'created_by' => $actor->id,
                    'summary' => [
                        ...($preRestore->summary ?? []),
                        'purpose' => 'pre_restore',
                        'recovered_after_restore' => true,
                    ],
                    'started_at' => $preRestore->started_at,
                    'completed_at' => now(),
                ]);
                $job = DataJob::query()->create([
                    'type' => 'restore',
                    'resource_type' => 'database',
                    'format' => 'sqlite',
                    'status' => 'succeeded',
                    'file_object_id' => $restoredSource->id,
                    'created_by' => $actor->id,
                    'summary' => [
                        ...$result,
                        'pre_restore_backup_id' => $recoveredPreRestore->id,
                        'restored_at' => $completedAt,
                    ],
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
                $this->activityLogger->record(
                    $job,
                    'database_backup.restored',
                    $actor,
                    after: $job->toArray(),
                    request: request(),
                );
                $this->sessionSecurity->purgeRestoredAuthenticationState();

                return $recoveredPreRestore->id;
            });
        } catch (Throwable $exception) {
            report($exception);
            try {
                DB::disconnect();
                $this->manager->restore($preRestorePath, $targetPath, self::REQUIRED_TABLES);
            } catch (Throwable $rollbackException) {
                report($rollbackException);
                DB::purge();
                DB::reconnect();
                throw ValidationException::withMessages([
                    'database' => 'تم تبديل قاعدة البيانات لكن تعذر إكمال سجل الاستعادة أو الرجوع التلقائي. نسخة ما قبل الاستعادة محفوظة وتتطلب تدخلاً فنياً.',
                ]);
            }
            DB::purge();
            DB::reconnect();
            DataJob::query()->whereKey($preRestore->id)->update([
                'status' => 'failed',
                'error_message' => 'Restore bookkeeping failed and the original database was restored.',
                'completed_at' => now(),
            ]);
            throw ValidationException::withMessages([
                'database' => 'فشل توثيق الاستعادة، لذلك تمت إعادة قاعدة البيانات الأصلية تلقائياً.',
            ]);
        }

        return [
            'restored_checksum_sha256' => $result['after_checksum'],
            'restored_database_checksum_sha256' => $result['after_checksum'],
            'pre_restore_backup_id' => $preRestoreJobId,
            'restored_at' => $completedAt,
            'files_restored' => 0,
        ];
    }

    /**
     * @return array{restored_checksum_sha256: string, restored_database_checksum_sha256: string, pre_restore_backup_id: int, restored_at: string, files_restored: int}
     */
    private function restoreBundle(
        FileObject $source,
        string $sourcePath,
        string $expectedChecksum,
        User $actor,
    ): array {
        $temporaryDirectory = $this->temporaryDirectory('project-desk-backup-restore-');
        $fileTransaction = [];
        $filesRolledBack = false;
        $restoreCompleted = false;

        try {
            try {
                $prepared = $this->bundleManager->prepare(
                    $sourcePath,
                    $temporaryDirectory.DIRECTORY_SEPARATOR.'source',
                );
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages([
                    'backup' => 'تعذر فك النسخة المشفرة أو فشل فحص سلامتها ومحتواها.',
                ]);
            }
            if (! hash_equals($source->checksum_sha256, $prepared['checksum_sha256'])
                || ! hash_equals($expectedChecksum, $prepared['checksum_sha256'])) {
                throw ValidationException::withMessages([
                    'checksum_sha256' => 'بصمة النسخة المشفرة لا تطابق الملف المحدد.',
                ]);
            }
            $validated = $this->manager->validate($prepared['database_path'], self::REQUIRED_TABLES);
            $this->compatibleSchemaFingerprint($prepared['database_path']);
            if (! $this->manager->containsActiveAdmin($prepared['database_path'], $actor->id)) {
                throw ValidationException::withMessages([
                    'database' => 'النسخة لا تحتوي حساب المدير الحالي بحالة نشطة، لذا لن تتم الاستعادة.',
                ]);
            }
            $filesComplete = ($prepared['manifest']['files_complete'] ?? false) === true;
            if (! $filesComplete) {
                try {
                    $this->bundleManager->assertDatabaseOnlyFilesAvailable($prepared['database_path']);
                } catch (Throwable $exception) {
                    report($exception);
                    throw ValidationException::withMessages([
                        'backup' => 'النسخة تحتوي مراجع ملفات غير مضمنة، ولم يمكن التحقق من وجودها وسلامتها في التخزين الخاص.',
                    ]);
                }
            }

            // The safety backup is itself a complete encrypted bundle. It is
            // prepared immediately so rollback does not depend on later I/O.
            $preRestore = $this->create($actor, 'pre_restore', [
                'restore_source_checksum_sha256' => $source->checksum_sha256,
            ]);
            $preRestoreFile = $preRestore->fileObject;
            if (! $preRestoreFile instanceof FileObject) {
                throw ValidationException::withMessages([
                    'database' => 'تعذر تثبيت مرجع نسخة ما قبل الاستعادة.',
                ]);
            }
            $preRestorePath = Storage::disk($preRestoreFile->disk)->path($preRestoreFile->storage_key);
            $preparedPreRestore = $this->bundleManager->prepare(
                $preRestorePath,
                $temporaryDirectory.DIRECTORY_SEPARATOR.'pre-restore',
            );
            $sourceMetadata = $this->fileMetadata($source);
            $preRestoreMetadata = $this->fileMetadata($preRestoreFile);

            if ($filesComplete) {
                $fileTransaction = $this->fileRestoreTransaction->stage(
                    $prepared['manifest']['files'],
                    $prepared['file_paths'],
                    $preparedPreRestore['manifest']['files'],
                );
                $this->fileRestoreTransaction->commit($fileTransaction);
            }

            $targetPath = $this->databasePath();
            $databaseSwapped = false;
            try {
                DB::disconnect();
                $databaseRestore = $this->manager->restore(
                    $prepared['database_path'],
                    $targetPath,
                    self::REQUIRED_TABLES,
                );
                $databaseSwapped = true;
                DB::purge();
                DB::reconnect();
            } catch (Throwable $exception) {
                report($exception);
                $databaseRollbackFailed = false;
                $fileRollbackFailed = false;
                try {
                    DB::purge();
                    if ($databaseSwapped) {
                        $this->manager->restore(
                            $preparedPreRestore['database_path'],
                            $targetPath,
                            self::REQUIRED_TABLES,
                        );
                    }
                    DB::reconnect();
                } catch (Throwable $rollbackException) {
                    report($rollbackException);
                    $databaseRollbackFailed = true;
                }
                try {
                    $this->fileRestoreTransaction->rollback($fileTransaction);
                    $filesRolledBack = true;
                } catch (Throwable $rollbackException) {
                    report($rollbackException);
                    $fileRollbackFailed = true;
                }
                if ($databaseRollbackFailed || $fileRollbackFailed) {
                    throw ValidationException::withMessages([
                        'database' => 'تعذرت الاستعادة وتعذر الرجوع التلقائي بالكامل. نسخة ما قبل الاستعادة محفوظة وتتطلب تدخلاً فنياً فورياً.',
                    ]);
                }
                throw ValidationException::withMessages([
                    'database' => 'فشلت الاستعادة وأعيدت ملفات المشروع إلى حالتها السابقة.',
                ]);
            }
            $completedAt = now()->toIso8601String();
            try {
                $preRestoreJobId = DB::transaction(function () use (
                    $actor,
                    $completedAt,
                    $databaseRestore,
                    $preRestore,
                    $preRestoreMetadata,
                    $prepared,
                    $sourceMetadata,
                ): int {
                    DataJob::query()->where('status', 'processing')->update([
                        'status' => 'failed',
                        'error_message' => 'The job was interrupted by a complete system restore.',
                        'completed_at' => now(),
                    ]);
                    $restoredSource = $this->restoreFileRecord($sourceMetadata, $actor);
                    $restoredPreRestore = $this->restoreFileRecord($preRestoreMetadata, $actor);
                    $recoveredPreRestore = DataJob::query()->create([
                        'type' => 'backup',
                        'resource_type' => 'system',
                        'format' => 'pdesk',
                        'status' => 'succeeded',
                        'file_object_id' => $restoredPreRestore->id,
                        'created_by' => $actor->id,
                        'summary' => [
                            ...($preRestore->summary ?? []),
                            'purpose' => 'pre_restore',
                            'recovered_after_restore' => true,
                        ],
                        'started_at' => $preRestore->started_at,
                        'completed_at' => now(),
                    ]);
                    $job = DataJob::query()->create([
                        'type' => 'restore',
                        'resource_type' => 'system',
                        'format' => 'pdesk',
                        'status' => 'succeeded',
                        'file_object_id' => $restoredSource->id,
                        'created_by' => $actor->id,
                        'summary' => [
                            ...$databaseRestore,
                            'source_bundle_checksum_sha256' => $prepared['checksum_sha256'],
                            'restored_database_checksum_sha256' => $prepared['manifest']['database']['checksum_sha256'],
                            'files_complete' => $prepared['manifest']['files_complete'],
                            'files_restored' => count($prepared['manifest']['files']),
                            'pre_restore_backup_id' => $recoveredPreRestore->id,
                            'restored_at' => $completedAt,
                        ],
                        'started_at' => now(),
                        'completed_at' => now(),
                    ]);
                    $this->activityLogger->record(
                        $job,
                        'database_backup.restored',
                        $actor,
                        after: $job->toArray(),
                        request: request(),
                    );
                    $this->sessionSecurity->purgeRestoredAuthenticationState();

                    return $recoveredPreRestore->id;
                });
            } catch (Throwable $exception) {
                report($exception);
                $databaseRollbackFailed = false;
                $fileRollbackFailed = false;
                try {
                    DB::disconnect();
                    $this->manager->restore(
                        $preparedPreRestore['database_path'],
                        $targetPath,
                        self::REQUIRED_TABLES,
                    );
                } catch (Throwable $rollbackException) {
                    report($rollbackException);
                    $databaseRollbackFailed = true;
                } finally {
                    DB::purge();
                    DB::reconnect();
                }
                try {
                    $this->fileRestoreTransaction->rollback($fileTransaction);
                    $filesRolledBack = true;
                } catch (Throwable $rollbackException) {
                    report($rollbackException);
                    $fileRollbackFailed = true;
                }

                if ($databaseRollbackFailed || $fileRollbackFailed) {
                    throw ValidationException::withMessages([
                        'database' => 'تعذر إكمال الاستعادة أو الرجوع التلقائي بالكامل. نسخة ما قبل الاستعادة محفوظة وتتطلب تدخلاً فنياً فورياً.',
                    ]);
                }
                DataJob::query()->whereKey($preRestore->id)->update([
                    'status' => 'failed',
                    'error_message' => 'Restore bookkeeping failed; database and files were rolled back.',
                    'completed_at' => now(),
                ]);
                throw ValidationException::withMessages([
                    'database' => 'فشل توثيق الاستعادة، لذلك أعيدت قاعدة البيانات والملفات الأصلية تلقائياً.',
                ]);
            }

            $restoreCompleted = true;
            try {
                $this->fileRestoreTransaction->cleanup($fileTransaction);
            } catch (Throwable $exception) {
                report($exception);
            }

            return [
                'restored_checksum_sha256' => $prepared['checksum_sha256'],
                'restored_database_checksum_sha256' => $validated['checksum_sha256'],
                'pre_restore_backup_id' => $preRestoreJobId,
                'restored_at' => $completedAt,
                'files_restored' => count($prepared['manifest']['files']),
            ];
        } finally {
            if (! $restoreCompleted && ! $filesRolledBack && $fileTransaction !== []) {
                try {
                    $this->fileRestoreTransaction->rollback($fileTransaction);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            if ($restoreCompleted || $filesRolledBack) {
                $this->fileRestoreTransaction->cleanup($fileTransaction);
            }
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    /**
     * @return array{disk: string, storage_key: string, original_name: string, mime_type: string, extension: string|null, size_bytes: int, checksum_sha256: string, scan_status: string, uploaded_at: string}
     */
    private function fileMetadata(FileObject $file): array
    {
        return [
            'disk' => $file->disk,
            'storage_key' => $file->storage_key,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'extension' => $file->extension,
            'size_bytes' => $file->size_bytes,
            'checksum_sha256' => $file->checksum_sha256,
            'scan_status' => $file->scan_status,
            'uploaded_at' => $file->uploaded_at->toDateTimeString(),
        ];
    }

    /**
     * @param  array{disk: string, storage_key: string, original_name: string, mime_type: string, extension: string|null, size_bytes: int, checksum_sha256: string, scan_status: string, uploaded_at: string}  $metadata
     */
    private function restoreFileRecord(array $metadata, User $actor): FileObject
    {
        return FileObject::query()->updateOrCreate(
            ['storage_key' => $metadata['storage_key']],
            [
                ...$metadata,
                'uploaded_by' => $actor->id,
            ],
        );
    }

    private function databasePath(): string
    {
        $database = DB::connection()->getConfig('database');
        if (! is_string($database) || $database === '' || $database === ':memory:') {
            throw new RuntimeException('تتطلب العملية قاعدة SQLite محفوظة في ملف فعلي.');
        }
        $real = realpath($database);
        if ($real === false || ! is_file($real)) {
            throw new RuntimeException('تعذر تحديد ملف قاعدة SQLite الحالية.');
        }

        return $real;
    }

    private function compatibleSchemaFingerprint(string $path): string
    {
        $candidate = $this->manager->schemaFingerprint($path);
        $current = $this->manager->connectionSchemaFingerprint(DB::connection()->getPdo());
        if (! hash_equals($current, $candidate)) {
            throw ValidationException::withMessages([
                'backup' => 'بنية النسخة لا تطابق إصدار قاعدة بيانات Project Desk الحالي.',
            ]);
        }

        return $candidate;
    }

    private function verifiedPath(FileObject $file): string
    {
        if ($file->scan_status !== 'safe' || ! in_array($file->extension, ['pdesk', 'sqlite', 'sqlite3', 'db'], true)) {
            throw ValidationException::withMessages(['backup' => 'ملف النسخة غير آمن أو ليس SQLite.']);
        }
        if (! Storage::disk($file->disk)->exists($file->storage_key)) {
            throw ValidationException::withMessages(['backup' => 'ملف النسخة غير موجود في التخزين الخاص.']);
        }
        $path = Storage::disk($file->disk)->path($file->storage_key);
        $maxBytes = (int) config('project-desk.data_center.backup_max_kilobytes', 512 * 1024) * 1024;
        if ((filesize($path) ?: 0) > $maxBytes) {
            throw ValidationException::withMessages(['backup' => 'يتجاوز ملف النسخة الحجم المسموح.']);
        }

        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum) || ! hash_equals($file->checksum_sha256, $checksum)) {
            throw ValidationException::withMessages(['backup' => 'بصمة ملف النسخة لا تطابق السجل المحفوظ.']);
        }

        return $path;
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(12));
        if (! mkdir($path, 0700, true)) {
            throw new RuntimeException('A secure temporary backup directory could not be created.');
        }

        return $path;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        if ($directory === '') {
            return;
        }
        $real = realpath($directory);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($real === false
            || $temporaryRoot === false
            || ! str_starts_with($real, rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'project-desk-backup-')) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            }
        }
        rmdir($real);
    }
}
