<?php

namespace Tests\Unit;

use App\Services\SqliteBackupManager;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SqliteBackupManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'project-desk-backup-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_creates_and_validates_consistent_snapshot_with_checksum(): void
    {
        $source = $this->database('source.sqlite', 'before');
        $backup = $this->directory.DIRECTORY_SEPARATOR.'backup.sqlite';
        $manager = new SqliteBackupManager;

        $result = $manager->snapshot($source, $backup, ['users', 'items']);

        $this->assertFileExists($backup);
        $this->assertSame('ok', $result['quick_check']);
        $this->assertSame(64, strlen($result['checksum_sha256']));
        $this->assertContains('items', $result['tables']);
        $this->assertGreaterThan(0, $result['size_bytes']);
    }

    #[Test]
    public function it_refuses_invalid_or_incomplete_database_files(): void
    {
        $manager = new SqliteBackupManager;
        $invalid = $this->directory.DIRECTORY_SEPARATOR.'invalid.sqlite';
        file_put_contents($invalid, 'not sqlite');

        $this->expectException(RuntimeException::class);
        $manager->validate($invalid, ['users']);
    }

    #[Test]
    public function it_restores_atomically_and_keeps_required_schema(): void
    {
        $target = $this->database('target.sqlite', 'before');
        $source = $this->database('restore.sqlite', 'after');
        $manager = new SqliteBackupManager;

        $result = $manager->restore($source, $target, ['users', 'items']);
        $pdo = new PDO('sqlite:'.$target);
        $statement = $pdo->query('SELECT value FROM items LIMIT 1');
        $this->assertNotFalse($statement);

        $this->assertSame('after', $statement->fetchColumn());
        $this->assertNotSame($result['before_checksum'], $result['after_checksum']);
        $this->assertSame(hash_file('sha256', $target), $result['after_checksum']);
    }

    #[Test]
    public function it_isolates_real_wal_and_shm_files_so_old_frames_cannot_leak_into_the_restored_database(): void
    {
        $target = $this->databaseWithUncheckpointedWal('wal-target.sqlite', 'before', 'only-in-old-wal');
        $source = $this->database('wal-restore.sqlite', 'after');
        $manager = $this->managerThatPreservesTargetSidecarsDuringPreflight($target);

        $this->assertFileExists($target.'-wal');
        $this->assertFileExists($target.'-shm');

        $manager->restore($source, $target, ['users', 'items']);

        $this->assertFileDoesNotExist($target.'-wal');
        $this->assertFileDoesNotExist($target.'-shm');
        $this->assertSame(['after'], $this->itemValues($target));
        $this->assertSame([], $this->restoreArtifacts());
    }

    #[Test]
    public function it_restores_the_original_main_database_and_real_sidecars_when_post_swap_validation_fails(): void
    {
        $target = $this->databaseWithUncheckpointedWal('rollback-target.sqlite', 'before', 'only-in-old-wal');
        $source = $this->database('rollback-restore.sqlite', 'after');
        $walChecksum = hash_file('sha256', $target.'-wal');
        $shmChecksum = hash_file('sha256', $target.'-shm');
        $manager = $this->managerThatPreservesTargetSidecarsDuringPreflight($target, failPostSwapValidation: true);

        try {
            $manager->restore($source, $target, ['users', 'items']);
            self::fail('The injected post-swap validation failure must abort the restore.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('فشل فحص ما بعد الاستعادة', $exception->getMessage());
        }

        $this->assertFileExists($target.'-wal');
        $this->assertFileExists($target.'-shm');
        $this->assertSame($walChecksum, hash_file('sha256', $target.'-wal'));
        $this->assertSame($shmChecksum, hash_file('sha256', $target.'-shm'));
        $this->assertSame(['before', 'only-in-old-wal'], $this->itemValues($target));
        $this->assertSame([], $this->restoreArtifacts());
    }

    #[Test]
    public function it_checks_current_active_admin_identity(): void
    {
        $database = $this->database('admins.sqlite', 'value');
        $manager = new SqliteBackupManager;

        $this->assertTrue($manager->containsActiveAdmin($database, 1));
        $this->assertFalse($manager->containsActiveAdmin($database, 2));
    }

    private function database(string $name, string $value): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, global_role TEXT, status TEXT, archived_at TEXT NULL)');
        $pdo->exec("INSERT INTO users VALUES (1, 'admin', 'active', NULL)");
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $statement = $pdo->prepare('INSERT INTO items (value) VALUES (:value)');
        $statement->execute(['value' => $value]);
        unset($pdo);

        return $path;
    }

    private function databaseWithUncheckpointedWal(string $name, string $value, string $walValue): string
    {
        $path = $this->database($name, $value);
        $baseline = $this->directory.DIRECTORY_SEPARATOR.'.wal-baseline-'.bin2hex(random_bytes(6)).'.sqlite';
        $preservedWal = $baseline.'-wal';
        $preservedShm = $baseline.'-shm';
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $journalMode = $pdo->query('PRAGMA journal_mode = WAL');
        self::assertNotFalse($journalMode);
        self::assertSame('wal', $journalMode->fetchColumn());
        unset($journalMode);
        $pdo->exec('PRAGMA wal_autocheckpoint = 0');
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        self::assertTrue(copy($path, $baseline));

        $statement = $pdo->prepare('INSERT INTO items (value) VALUES (:value)');
        $statement->execute(['value' => $walValue]);
        self::assertFileExists($path.'-wal');
        self::assertFileExists($path.'-shm');
        self::assertGreaterThan(32, filesize($path.'-wal'));
        self::assertGreaterThan(0, filesize($path.'-shm'));
        self::assertTrue(copy($path.'-wal', $preservedWal));
        self::assertTrue(copy($path.'-shm', $preservedShm));
        unset($statement, $pdo);

        self::assertTrue(copy($baseline, $path));
        self::assertTrue(copy($preservedWal, $path.'-wal'));
        self::assertTrue(copy($preservedShm, $path.'-shm'));
        unlink($baseline);
        unlink($preservedWal);
        unlink($preservedShm);

        return $path;
    }

    private function managerThatPreservesTargetSidecarsDuringPreflight(
        string $targetPath,
        bool $failPostSwapValidation = false,
    ): SqliteBackupManager {
        return new class($targetPath, $failPostSwapValidation) extends SqliteBackupManager
        {
            private int $validationCalls = 0;

            public function __construct(
                private readonly string $targetPath,
                private readonly bool $failPostSwapValidation,
            ) {}

            /**
             * The normal preflight may checkpoint a recovered WAL when its PDO
             * closes. This test double leaves the real generated sidecars in
             * place so the swap and rollback paths exercise them directly.
             *
             * @param  list<string>  $requiredTables
             * @return array{size_bytes: int, checksum_sha256: string, quick_check: string, tables: list<string>}
             */
            public function validate(string $path, array $requiredTables = []): array
            {
                $this->validationCalls++;

                if ($this->validationCalls === 2 && $path === $this->targetPath) {
                    $checksum = hash_file('sha256', $path);
                    $size = filesize($path);
                    if (! is_string($checksum) || ! is_int($size)) {
                        throw new RuntimeException('Unable to inspect the WAL test database.');
                    }

                    return [
                        'size_bytes' => $size,
                        'checksum_sha256' => $checksum,
                        'quick_check' => 'ok',
                        'tables' => ['items', 'users'],
                    ];
                }

                if ($this->validationCalls === 4 && $this->failPostSwapValidation) {
                    throw new RuntimeException('Injected post-swap validation failure.');
                }

                return parent::validate($path, $requiredTables);
            }
        };
    }

    /** @return list<string> */
    private function itemValues(string $path): array
    {
        $pdo = new PDO('sqlite:'.$path);
        $statement = $pdo->query('SELECT value FROM items ORDER BY id');
        self::assertNotFalse($statement);
        $values = $statement->fetchAll(PDO::FETCH_COLUMN);
        unset($statement, $pdo);

        return array_values(array_map('strval', $values));
    }

    /** @return list<string> */
    private function restoreArtifacts(): array
    {
        $artifacts = glob($this->directory.DIRECTORY_SEPARATOR.'.restore-*');

        return $artifacts === false ? [] : $artifacts;
    }
}
