<?php

namespace Tests\Feature;

use App\Models\FileObject;
use App\Models\User;
use App\Services\RestoreNonceManager;
use App\Services\SqliteBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDO;
use Tests\Support\ProjectDeskTestData;
use Tests\Support\RegistersDataCenterRoutes;
use Tests\TestCase;

class SqliteBackupControllerTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase, RegistersDataCenterRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->registerDataCenterRoutes();
    }

    public function test_non_admin_cannot_create_validate_download_or_restore_backup(): void
    {
        $manager = $this->makeUser('project_manager');
        $backup = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'backups/test.sqlite',
            'original_name' => 'test.sqlite',
            'mime_type' => 'application/vnd.sqlite3',
            'extension' => 'sqlite',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'scan_status' => 'safe',
            'uploaded_by' => $manager->id,
            'uploaded_at' => now(),
        ]);

        $this->actingAs($manager)->postJson(route('data-center.backups.store'))->assertForbidden();
        $this->post(route('data-center.backups.upload'), [
            'file' => UploadedFile::fake()->create('external.sqlite', 20, 'application/vnd.sqlite3'),
        ])->assertForbidden();
        $this->actingAs($manager)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('data-center.backups.validate', $backup))->assertForbidden();
        $this->get(route('data-center.backups.download', $backup))->assertForbidden();
        $this->actingAs($manager)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('data-center.backups.restore', $backup), [
                'confirmation' => 'RESTORE PROJECT DESK',
                'checksum_sha256' => str_repeat('a', 64),
                'restore_nonce' => str_repeat('a', 64).'.'.str_repeat('b', 64),
            ])->assertForbidden();
    }

    public function test_restore_requires_exact_typed_confirmation_before_service_runs(): void
    {
        $admin = $this->makeUser('admin');
        $backup = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'backups/test.sqlite',
            'original_name' => 'test.sqlite',
            'mime_type' => 'application/vnd.sqlite3',
            'extension' => 'sqlite',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('data-center.backups.restore', $backup), [
                'confirmation' => 'restore',
                'checksum_sha256' => str_repeat('a', 64),
                'restore_nonce' => str_repeat('a', 64).'.'.str_repeat('b', 64),
            ])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertDatabaseCount('data_jobs', 0);
    }

    public function test_restore_route_holds_maintenance_fence_until_service_finishes(): void
    {
        $admin = $this->makeUser('admin');
        $checksum = str_repeat('b', 64);
        $backup = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'backups/fenced.pdesk',
            'original_name' => 'fenced.pdesk',
            'mime_type' => 'application/vnd.projectdesk.backup',
            'extension' => 'pdesk',
            'size_bytes' => 100,
            'checksum_sha256' => $checksum,
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);
        $service = Mockery::mock(SqliteBackupService::class);
        $service->shouldReceive('restore')
            ->once()
            ->withArgs(fn (FileObject $file, string $expected, User $actor): bool => $file->is($backup)
                && $expected === $checksum
                && $actor->is($admin)
                && $this->app->isDownForMaintenance())
            ->andReturn([
                'restored_checksum_sha256' => $checksum,
                'restored_database_checksum_sha256' => $checksum,
                'pre_restore_backup_id' => 1,
                'restored_at' => now()->toIso8601String(),
                'files_restored' => 0,
            ]);
        $this->app->instance(SqliteBackupService::class, $service);

        $service->shouldReceive('validate')->once()->withArgs(
            fn (FileObject $file, User $actor): bool => $file->is($backup) && $actor->is($admin),
        )->andReturn(['checksum_sha256' => $checksum, 'quick_check' => 'ok']);
        $validation = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('data-center.backups.validate', $backup))
            ->assertOk();
        $nonce = (string) $validation->json('data.restore_nonce');

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('data-center.backups.restore', $backup), [
                'confirmation' => 'RESTORE PROJECT DESK',
                'checksum_sha256' => $checksum,
                'restore_nonce' => $nonce,
            ])
            ->assertOk()
            ->assertJsonPath('data.restored_checksum_sha256', $checksum);

        $this->assertFalse($this->app->isDownForMaintenance());
    }

    public function test_restore_requires_recent_password_confirmation(): void
    {
        $admin = $this->makeUser('admin');
        $backup = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'backups/step-up.pdesk',
            'original_name' => 'step-up.pdesk',
            'mime_type' => 'application/vnd.projectdesk.backup',
            'extension' => 'pdesk',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('c', 64),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        $this->actingAs($admin)->postJson(route('data-center.backups.restore', $backup), [
            'confirmation' => 'RESTORE PROJECT DESK',
            'checksum_sha256' => $backup->checksum_sha256,
            'restore_nonce' => str_repeat('a', 64).'.'.str_repeat('b', 64),
        ])->assertStatus(423);
        $this->assertDatabaseCount('data_jobs', 0);
    }

    public function test_restore_nonce_is_bound_and_consumed_once(): void
    {
        $admin = $this->makeUser('admin');
        $backup = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'backups/nonce.pdesk',
            'original_name' => 'nonce.pdesk',
            'mime_type' => 'application/vnd.projectdesk.backup',
            'extension' => 'pdesk',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('d', 64),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);
        $nonces = $this->app->make(RestoreNonceManager::class);
        $nonce = $nonces->issue($admin, $backup);
        $nonces->consume($admin, $backup, $backup->checksum_sha256, $nonce);

        $this->expectException(ValidationException::class);
        $nonces->consume($admin, $backup, $backup->checksum_sha256, $nonce);
    }

    public function test_validate_rejects_missing_backup_without_leaking_path(): void
    {
        $admin = $this->makeUser('admin');
        $backup = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'backups/missing.sqlite',
            'original_name' => 'missing.sqlite',
            'mime_type' => 'application/vnd.sqlite3',
            'extension' => 'sqlite',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('data-center.backups.validate', $backup))
            ->assertUnprocessable()->assertJsonValidationErrors('backup');
    }

    public function test_admin_can_upload_validate_and_download_a_structurally_valid_external_backup(): void
    {
        $admin = $this->makeUser('admin');
        $path = $this->makeSqliteBackupFile($admin->id);

        try {
            $response = $this->actingAs($admin)
                ->withHeader('Accept', 'application/json')
                ->post(route('data-center.backups.upload'), [
                    'file' => new UploadedFile(
                        $path,
                        'external-project-desk.sqlite',
                        'application/vnd.sqlite3',
                        null,
                        true,
                    ),
                ]);

            $response->assertCreated()
                ->assertJsonPath('data.status', 'succeeded')
                ->assertJsonPath('data.type', 'backup_upload')
                ->assertJsonPath('data.summary.quick_check', 'ok')
                ->assertJsonPath('data.summary.schema_fingerprint', fn (mixed $value): bool => is_string($value) && strlen($value) === 64)
                ->assertJsonPath('data.summary.contains_current_admin', true)
                ->assertJsonPath('data.summary.encrypted', true)
                ->assertJsonPath('data.summary.files_complete', false)
                ->assertJsonPath('data.summary.legacy_database_only', true)
                ->assertJsonPath('data.file_object.scan_status', 'safe');

            $backup = FileObject::query()->sole();
            Storage::disk('local')->assertExists($backup->storage_key);
            self::assertSame('pdesk', $backup->extension);
            $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
                ->postJson(route('data-center.backups.validate', $backup))
                ->assertOk()
                ->assertJsonPath('data.quick_check', 'ok')
                ->assertJsonPath('data.schema_compatible', true)
                ->assertJsonPath('data.contains_current_admin', true)
                ->assertJsonPath('data.encrypted', true)
                ->assertJsonPath('data.files_complete', false)
                ->assertJsonPath('data.checksum_sha256', $backup->checksum_sha256);

            $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
                ->get(route('data-center.backups.download', $backup))
                ->assertOk()
                ->assertHeader('X-Checksum-SHA256', $backup->checksum_sha256)
                ->assertHeader('Content-Type', 'application/vnd.projectdesk.backup')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertDatabaseHas('activity_logs', [
                'action' => 'database_backup.downloaded',
                'subject_id' => $backup->id,
            ]);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_backup_download_requires_recent_password_and_does_not_audit_missing_storage(): void
    {
        $admin = $this->makeUser('admin');
        $backup = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'backups/missing.pdesk',
            'original_name' => 'missing.pdesk',
            'mime_type' => 'application/vnd.projectdesk.backup',
            'extension' => 'pdesk',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('e', 64),
            'scan_status' => 'safe',
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->get(route('data-center.backups.download', $backup))
            ->assertStatus(423);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('data-center.backups.download', $backup))
            ->assertNotFound();

        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'database_backup.downloaded',
            'subject_id' => $backup->id,
        ]);
    }

    public function test_invalid_sqlite_upload_is_rejected_and_never_marked_safe(): void
    {
        $admin = $this->makeUser('admin');
        $path = tempnam(sys_get_temp_dir(), 'project-desk-invalid-');
        self::assertIsString($path);
        file_put_contents($path, "SQLite format 3\0".str_repeat("\0", 256));

        try {
            $this->actingAs($admin)
                ->withHeader('Accept', 'application/json')
                ->post(route('data-center.backups.upload'), [
                    'file' => new UploadedFile(
                        $path,
                        'invalid.sqlite',
                        'application/octet-stream',
                        null,
                        true,
                    ),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file');

            $this->assertDatabaseCount('file_objects', 0);
            $this->assertDatabaseHas('data_jobs', [
                'type' => 'backup_upload',
                'status' => 'failed',
                'file_object_id' => null,
            ]);
            self::assertSame([], Storage::disk('local')->allFiles('backups/project-desk/uploads'));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_sqlite_with_incompatible_project_desk_schema_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $path = $this->makeSqliteBackupFile($admin->id);
        $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('ALTER TABLE clients ADD COLUMN unexpected_restore_column TEXT NULL');
        unset($pdo);

        try {
            $this->actingAs($admin)
                ->withHeader('Accept', 'application/json')
                ->post(route('data-center.backups.upload'), [
                    'file' => new UploadedFile(
                        $path,
                        'incompatible.sqlite',
                        'application/vnd.sqlite3',
                        null,
                        true,
                    ),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file');

            $this->assertDatabaseCount('file_objects', 0);
            $this->assertDatabaseHas('data_jobs', [
                'type' => 'backup_upload',
                'status' => 'failed',
            ]);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function makeSqliteBackupFile(int $adminId): string
    {
        self::assertDatabaseHas('users', ['id' => $adminId, 'global_role' => 'admin', 'status' => 'active']);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'project-desk-valid-'.bin2hex(random_bytes(8)).'.sqlite';
        $source = $this->app->make('db')->connection()->getPdo();
        $schemaStatement = $source->query(
            "SELECT sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 1 WHEN 'index' THEN 2 WHEN 'trigger' THEN 3 ELSE 4 END, name",
        );
        self::assertNotFalse($schemaStatement);
        $schema = $schemaStatement->fetchAll(PDO::FETCH_COLUMN);
        $target = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($schema as $sql) {
            self::assertIsString($sql);
            $target->exec($sql);
        }

        $userStatement = $source->prepare('SELECT * FROM users WHERE id = :id');
        $userStatement->execute(['id' => $adminId]);
        $user = $userStatement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($user);
        $columns = array_keys($user);
        $quotedColumns = array_map(static fn (string $column): string => '"'.$column.'"', $columns);
        $placeholders = array_map(static fn (string $column): string => ':'.$column, $columns);
        $insert = $target->prepare(
            'INSERT INTO users ('.implode(', ', $quotedColumns).') VALUES ('.implode(', ', $placeholders).')',
        );
        $insert->execute($user);

        return $path;
    }
}
