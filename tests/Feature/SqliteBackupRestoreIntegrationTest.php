<?php

namespace Tests\Feature;

use App\Models\DataJob;
use App\Models\FileObject;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\RestoreNonceManager;
use App\Services\SqliteBackupManager;
use App\Services\SqliteBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SqliteBackupRestoreIntegrationTest extends TestCase
{
    private string $databasePath;

    private mixed $originalDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        $this->originalDatabase = config('database.connections.sqlite.database');
        $path = tempnam(sys_get_temp_dir(), 'project-desk-restore-');
        self::assertIsString($path);
        $this->databasePath = $path;
        config(['database.connections.sqlite.database' => $this->databasePath]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::disconnect('sqlite');
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => $this->originalDatabase]);
        foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_database_only_restore_keeps_existing_verified_blobs_untouched(): void
    {
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        $storageKey = 'projects/database-only/evidence.txt';
        $contents = 'database-only evidence already present in private storage';
        Storage::disk('local')->put($storageKey, $contents);
        $file = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => $storageKey,
            'original_name' => 'evidence.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);
        $service = $this->app->make(SqliteBackupService::class);
        $backup = $this->uploadDatabaseOnlySnapshot($service, $admin);
        SystemSetting::query()->create([
            'group' => 'general',
            'key' => 'created_after_database_only_snapshot',
            'value' => true,
            'is_secret' => false,
        ]);

        $result = $service->restore($backup, $backup->checksum_sha256, $admin);

        $this->assertDatabaseMissing('system_settings', [
            'group' => 'general',
            'key' => 'created_after_database_only_snapshot',
        ]);
        $this->assertDatabaseHas('file_objects', [
            'id' => $file->id,
            'storage_key' => $storageKey,
            'checksum_sha256' => hash('sha256', $contents),
        ]);
        Storage::disk('local')->assertExists($storageKey);
        self::assertSame($contents, Storage::disk('local')->get($storageKey));
        self::assertSame(0, $result['files_restored']);
        self::assertSame([], Storage::disk('local')->allFiles('backups/project-desk/restore-staging'));
    }

    public function test_database_only_restore_rejects_a_checksum_mismatch_before_any_mutation(): void
    {
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        $storageKey = 'projects/database-only/checksum.txt';
        $contents = 'expected database-only file';
        Storage::disk('local')->put($storageKey, $contents);
        FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => $storageKey,
            'original_name' => 'checksum.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);
        $service = $this->app->make(SqliteBackupService::class);
        $backup = $this->uploadDatabaseOnlySnapshot($service, $admin);
        Storage::disk('local')->put($storageKey, str_repeat('x', strlen($contents)));
        SystemSetting::query()->create([
            'group' => 'general',
            'key' => 'must_survive_rejected_restore',
            'value' => true,
            'is_secret' => false,
        ]);

        $nonce = $this->app->make(RestoreNonceManager::class)->issue($admin, $backup);
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('data-center.backups.restore', $backup), [
                'confirmation' => 'RESTORE PROJECT DESK',
                'checksum_sha256' => $backup->checksum_sha256,
                'restore_nonce' => $nonce,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('backup');

        $this->assertDatabaseHas('system_settings', [
            'group' => 'general',
            'key' => 'must_survive_rejected_restore',
        ]);
        self::assertSame(0, DataJob::query()->where('type', 'backup')->count());
        self::assertSame(str_repeat('x', strlen($contents)), Storage::disk('local')->get($storageKey));
    }

    public function test_database_only_restore_rejects_a_missing_blob_before_any_mutation(): void
    {
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        $storageKey = 'projects/database-only/missing.txt';
        $contents = 'file that will be missing before restore';
        Storage::disk('local')->put($storageKey, $contents);
        FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => $storageKey,
            'original_name' => 'missing.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);
        $service = $this->app->make(SqliteBackupService::class);
        $backup = $this->uploadDatabaseOnlySnapshot($service, $admin);
        Storage::disk('local')->delete($storageKey);
        SystemSetting::query()->create([
            'group' => 'general',
            'key' => 'missing_blob_restore_rejected',
            'value' => true,
            'is_secret' => false,
        ]);

        try {
            $service->restore($backup, $backup->checksum_sha256, $admin);
            self::fail('A database-only restore must reject a missing external file.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('backup', $exception->errors());
        }

        $this->assertDatabaseHas('system_settings', [
            'group' => 'general',
            'key' => 'missing_blob_restore_rejected',
        ]);
        self::assertSame(0, DataJob::query()->where('type', 'backup')->count());
    }

    public function test_restore_reconciles_file_records_and_keeps_a_recoverable_pre_restore_backup(): void
    {
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        $storageKey = 'projects/complete-backup/reference.txt';
        $originalContents = 'contents captured in the source backup';
        Storage::disk('local')->put($storageKey, $originalContents);
        $projectFile = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => $storageKey,
            'original_name' => 'reference.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($originalContents),
            'checksum_sha256' => hash('sha256', $originalContents),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);
        $rememberTokenBeforeBackup = $admin->remember_token;
        DB::table('sessions')->insert([
            'id' => 'logged-out-before-restore',
            'user_id' => $admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'restore-security-test',
            'payload' => 'test',
            'last_activity' => time(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $admin->email,
            'token' => 'already-consumed-reset-token',
            'created_at' => now(),
        ]);
        $service = $this->app->make(SqliteBackupService::class);
        $sourceJob = $service->create($admin);
        $source = $sourceJob->fileObject;
        self::assertInstanceOf(FileObject::class, $source);
        self::assertSame('pdesk', $source->extension);
        self::assertSame('system', $sourceJob->resource_type);
        self::assertSame(1, $sourceJob->summary['files_count'] ?? null);
        self::assertTrue((bool) ($sourceJob->summary['encrypted'] ?? false));
        DB::table('sessions')->where('id', 'logged-out-before-restore')->delete();
        DB::table('password_reset_tokens')->where('email', $admin->email)->delete();

        $changedContents = 'a legitimate newer revision before restoration';
        Storage::disk('local')->put($storageKey, $changedContents);
        $projectFile->update([
            'size_bytes' => strlen($changedContents),
            'checksum_sha256' => hash('sha256', $changedContents),
        ]);
        $extraStorageKey = 'projects/complete-backup/created-later.txt';
        $extraContents = 'file created after the source backup';
        Storage::disk('local')->put($extraStorageKey, $extraContents);
        FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => $extraStorageKey,
            'original_name' => 'created-later.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($extraContents),
            'checksum_sha256' => hash('sha256', $extraContents),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);
        SystemSetting::query()->create([
            'group' => 'general',
            'key' => 'created_after_source_backup',
            'value' => true,
            'is_secret' => false,
        ]);

        $result = $service->restore($source, $source->checksum_sha256, $admin);

        $this->assertDatabaseMissing('system_settings', [
            'group' => 'general',
            'key' => 'created_after_source_backup',
        ]);
        $restoreJob = DataJob::query()->where('type', 'restore')->sole();
        self::assertSame('succeeded', $restoreJob->status);
        self::assertSame($result['restored_checksum_sha256'], $source->checksum_sha256);
        self::assertSame(1, $result['files_restored']);
        self::assertSame($originalContents, Storage::disk('local')->get($storageKey));
        Storage::disk('local')->assertMissing($extraStorageKey);
        $this->assertDatabaseMissing('file_objects', ['storage_key' => $extraStorageKey]);
        $this->assertDatabaseHas('file_objects', [
            'id' => $projectFile->id,
            'checksum_sha256' => hash('sha256', $originalContents),
        ]);
        $preRestoreJob = DataJob::query()->findOrFail($result['pre_restore_backup_id']);
        self::assertSame('succeeded', $preRestoreJob->status);
        self::assertSame('pre_restore', $preRestoreJob->summary['purpose'] ?? null);
        self::assertTrue((bool) ($preRestoreJob->summary['recovered_after_restore'] ?? false));
        self::assertSame(2, $preRestoreJob->summary['files_count'] ?? null);
        self::assertInstanceOf(FileObject::class, $preRestoreJob->fileObject);
        Storage::disk('local')->assertExists($preRestoreJob->fileObject->storage_key);
        self::assertSame('pdesk', $preRestoreJob->fileObject->extension);
        self::assertSame('safe', $preRestoreJob->fileObject->scan_status);
        self::assertSame(0, DataJob::query()->where('status', 'processing')->count());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'database_backup.restored',
            'subject_type' => DataJob::class,
            'subject_id' => $restoreJob->id,
        ]);
        $this->assertDatabaseMissing('sessions', ['id' => 'logged-out-before-restore']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
        self::assertNotSame($rememberTokenBeforeBackup, User::query()->findOrFail($admin->id)->remember_token);
    }

    public function test_complete_backups_are_encrypted_verified_and_do_not_recursively_include_backup_packages(): void
    {
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        $contents = 'project evidence';
        Storage::disk('local')->put('projects/evidence.txt', $contents);
        FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'projects/evidence.txt',
            'original_name' => 'evidence.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);
        $service = $this->app->make(SqliteBackupService::class);

        $first = $service->create($admin);
        $second = $service->create($admin);

        self::assertSame(1, $first->summary['files_count'] ?? null);
        self::assertSame(1, $second->summary['files_count'] ?? null);
        self::assertTrue((bool) ($second->summary['verified_after_create'] ?? false));
        self::assertSame('aes-256-gcm-chunked', $second->summary['cipher'] ?? null);
        self::assertSame(16, strlen((string) ($second->summary['key_id'] ?? '')));
        $file = $second->fileObject;
        self::assertInstanceOf(FileObject::class, $file);
        $path = Storage::disk('local')->path($file->storage_key);
        self::assertNotSame("SQLite format 3\0", file_get_contents($path, false, null, 0, 16));
        self::assertSame(hash_file('sha256', $path), $file->checksum_sha256);
    }

    public function test_tampered_encrypted_backup_is_rejected_even_if_its_outer_checksum_record_is_changed(): void
    {
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        $service = $this->app->make(SqliteBackupService::class);
        $job = $service->create($admin);
        $file = $job->fileObject;
        self::assertInstanceOf(FileObject::class, $file);
        $path = Storage::disk('local')->path($file->storage_key);
        $stream = fopen($path, 'r+b');
        self::assertIsResource($stream);
        fseek($stream, -1, SEEK_END);
        $last = fread($stream, 1);
        self::assertIsString($last);
        fseek($stream, -1, SEEK_END);
        fwrite($stream, $last === "\0" ? "\1" : "\0");
        fclose($stream);
        $file->update(['checksum_sha256' => hash_file('sha256', $path)]);

        try {
            $service->validate($file->fresh(), $admin);
            self::fail('A GCM-tampered backup must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('backup', $exception->errors());
        }
    }

    public function test_backup_key_rotation_requires_the_matching_active_or_previous_key(): void
    {
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        $oldKey = 'base64:'.base64_encode(str_repeat('o', 32));
        $newKey = 'base64:'.base64_encode(str_repeat('n', 32));
        config([
            'project-desk.data_center.backup_encryption_key' => $oldKey,
            'project-desk.data_center.backup_previous_encryption_keys' => [],
        ]);
        $service = $this->app->make(SqliteBackupService::class);
        $job = $service->create($admin);
        $file = $job->fileObject;
        self::assertInstanceOf(FileObject::class, $file);

        config(['project-desk.data_center.backup_encryption_key' => $newKey]);
        try {
            $service->validate($file, $admin);
            self::fail('A backup encrypted with an unavailable key must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('backup', $exception->errors());
        }

        config(['project-desk.data_center.backup_previous_encryption_keys' => [$oldKey]]);
        $validation = $service->validate($file, $admin);
        self::assertTrue((bool) $validation['encrypted']);
        self::assertIsString($validation['key_id']);
        self::assertSame(16, strlen($validation['key_id']));
    }

    public function test_unsafe_storage_keys_are_rejected_before_a_backup_package_is_written(): void
    {
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => '../outside-private-storage.txt',
            'original_name' => 'unsafe.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => 1,
            'checksum_sha256' => hash('sha256', 'x'),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        try {
            $this->app->make(SqliteBackupService::class)->create($admin);
            self::fail('A path-traversal storage key must prevent backup creation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('database', $exception->errors());
        }

        self::assertSame([], Storage::disk('local')->allFiles('backups/project-desk'));
        $this->assertDatabaseHas('data_jobs', ['type' => 'backup', 'status' => 'failed']);
    }

    public function test_automatic_backup_command_creates_and_verifies_a_real_sqlite_snapshot(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 03:00:00', 'Africa/Tripoli'));
        $admin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => null,
        ]);
        foreach ([
            'enabled' => true,
            'frequency' => 'daily',
            'time' => '02:00',
            'retention_count' => 30,
        ] as $key => $value) {
            SystemSetting::query()->create([
                'group' => 'automatic_backup',
                'key' => $key,
                'value' => $value,
                'is_secret' => false,
            ]);
        }

        $this->artisan('project-desk:automatic-backup')->assertSuccessful();

        $job = DataJob::query()->where('type', 'backup')->sole();
        self::assertSame('succeeded', $job->status);
        self::assertSame($admin->id, $job->created_by);
        self::assertSame('automatic', $job->summary['purpose'] ?? null);
        self::assertSame('automatic:daily:2026-08-12', $job->summary['automatic_period_key'] ?? null);
        self::assertSame('ok', $job->summary['quick_check'] ?? null);
        self::assertIsString($job->summary['checksum_sha256'] ?? null);
        self::assertInstanceOf(FileObject::class, $job->fileObject);
        Storage::disk('local')->assertExists($job->fileObject->storage_key);
    }

    private function uploadDatabaseOnlySnapshot(SqliteBackupService $service, User $admin): FileObject
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'project-desk-database-only-'.bin2hex(random_bytes(8)).'.sqlite';
        try {
            $this->app->make(SqliteBackupManager::class)->snapshot($this->databasePath, $path);
            $job = $service->upload(new UploadedFile(
                $path,
                'database-only.sqlite',
                'application/vnd.sqlite3',
                null,
                true,
            ), $admin);
            self::assertFalse((bool) ($job->summary['files_complete'] ?? true));
            self::assertTrue((bool) ($job->summary['legacy_database_only'] ?? false));
            $backup = $job->fileObject;
            self::assertInstanceOf(FileObject::class, $backup);

            return $backup;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
