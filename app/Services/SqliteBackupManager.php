<?php

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

class SqliteBackupManager
{
    /** @var list<string> */
    private const SIDECAR_SUFFIXES = ['-wal', '-shm'];

    /**
     * @param  list<string>  $requiredTables
     * @return array{size_bytes: int, checksum_sha256: string, quick_check: string, tables: list<string>}
     */
    public function snapshot(string $sourcePath, string $destinationPath, array $requiredTables = []): array
    {
        $this->assertReadableFile($sourcePath);
        if (file_exists($destinationPath)) {
            throw new RuntimeException('ملف النسخة المستهدف موجود مسبقاً.');
        }
        $directory = dirname($destinationPath);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('تعذر إنشاء مجلد النسخ الاحتياطية.');
        }

        $pdo = $this->connect($sourcePath);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('VACUUM INTO '.$pdo->quote($destinationPath));

        return $this->validate($destinationPath, $requiredTables);
    }

    /**
     * @param  list<string>  $requiredTables
     * @return array{size_bytes: int, checksum_sha256: string, quick_check: string, tables: list<string>}
     */
    public function validate(string $path, array $requiredTables = []): array
    {
        $this->assertReadableFile($path);
        $pdo = $this->connect($path);
        $pdo->exec('PRAGMA query_only = ON');
        $quickCheckStatement = $pdo->query('PRAGMA quick_check');
        if ($quickCheckStatement === false) {
            throw new RuntimeException('تعذر تشغيل فحص سلامة SQLite.');
        }
        $quickCheck = $quickCheckStatement->fetchColumn();
        if ($quickCheck !== 'ok') {
            throw new RuntimeException('فشل فحص سلامة قاعدة SQLite.');
        }

        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        if ($statement === false) {
            throw new RuntimeException('تعذر قراءة بنية قاعدة SQLite.');
        }
        $tables = $statement->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_values(array_map('strval', $tables));
        foreach ($requiredTables as $requiredTable) {
            if (! in_array($requiredTable, $tables, true)) {
                throw new RuntimeException("النسخة لا تحتوي الجدول المطلوب {$requiredTable}.");
            }
        }

        $checksum = hash_file('sha256', $path);
        $size = filesize($path);
        if (! is_string($checksum) || ! is_int($size) || $size <= 0) {
            throw new RuntimeException('تعذر قياس النسخة أو حساب بصمتها.');
        }

        return [
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
            'quick_check' => 'ok',
            'tables' => $tables,
        ];
    }

    public function containsActiveAdmin(string $path, int $userId): bool
    {
        $this->validate($path, ['users']);
        $pdo = $this->connect($path);
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM users WHERE id = :id AND global_role = 'admin' AND status = 'active' AND archived_at IS NULL",
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function schemaFingerprint(string $path): string
    {
        $this->assertReadableFile($path);

        return $this->fingerprint($this->connect($path));
    }

    public function connectionSchemaFingerprint(PDO $pdo): string
    {
        return $this->fingerprint($pdo);
    }

    /**
     * @param  list<string>  $requiredTables
     * @return array{before_checksum: string, after_checksum: string}
     */
    public function restore(string $sourcePath, string $targetPath, array $requiredTables = []): array
    {
        $source = $this->validate($sourcePath, $requiredTables);
        $before = $this->validate($targetPath, $requiredTables);
        if ($this->samePath($sourcePath, $targetPath)) {
            throw new RuntimeException('مصدر الاستعادة لا يمكن أن يكون قاعدة البيانات نفسها.');
        }

        $directory = dirname($targetPath);
        $token = bin2hex(random_bytes(12));
        $incoming = $directory.DIRECTORY_SEPARATOR.'.restore-incoming-'.$token.'.sqlite';
        $rollback = $directory.DIRECTORY_SEPARATOR.'.restore-rollback-'.$token.'.sqlite';
        $failed = $directory.DIRECTORY_SEPARATOR.'.restore-failed-'.$token.'.sqlite';
        if (! copy($sourcePath, $incoming)) {
            throw new RuntimeException('تعذر تجهيز ملف الاستعادة المؤقت.');
        }

        /** @var list<string> $originalSidecars */
        $originalSidecars = [];

        try {
            $this->validate($incoming, $requiredTables);

            $originalSidecars = $this->moveExistingSidecars($targetPath, $rollback);
            try {
                if (! rename($targetPath, $rollback)) {
                    throw new RuntimeException('تعذر عزل قاعدة البيانات الحالية قبل الاستعادة.');
                }
            } catch (Throwable $exception) {
                $this->moveRequiredSidecars($rollback, $targetPath, $originalSidecars);

                throw $exception;
            }

            if (! rename($incoming, $targetPath)) {
                $this->rollbackSwap($targetPath, $rollback, $failed, $originalSidecars);

                throw new RuntimeException('تعذر تثبيت قاعدة البيانات المستعادة.');
            }

            try {
                $after = $this->validate($targetPath, $requiredTables);
            } catch (Throwable $exception) {
                $this->rollbackSwap($targetPath, $rollback, $failed, $originalSidecars);

                throw new RuntimeException('فشل فحص ما بعد الاستعادة وتم الرجوع للقاعدة السابقة.', previous: $exception);
            }

            $this->removeDatabaseSet($rollback);

            return ['before_checksum' => $before['checksum_sha256'], 'after_checksum' => $after['checksum_sha256']];
        } finally {
            $this->removeDatabaseSet($incoming);
        }
    }

    /**
     * Move any live SQLite WAL/SHM files away from a database pathname before
     * replacing its main file. The returned suffixes identify the exact set
     * that must travel back with the main database during rollback.
     *
     * @return list<string>
     */
    private function moveExistingSidecars(string $fromBase, string $toBase): array
    {
        $moved = [];

        try {
            foreach (self::SIDECAR_SUFFIXES as $suffix) {
                $source = $fromBase.$suffix;
                if (! file_exists($source)) {
                    continue;
                }

                $destination = $toBase.$suffix;
                if (file_exists($destination) || ! rename($source, $destination)) {
                    throw new RuntimeException('تعذر عزل ملفات SQLite الجانبية قبل الاستعادة.');
                }

                $moved[] = $suffix;
            }
        } catch (Throwable $exception) {
            try {
                $this->moveRequiredSidecars($toBase, $fromBase, $moved);
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    'تعذر عزل ملفات SQLite الجانبية وتعذر التراجع عن العزل الجزئي.',
                    previous: $rollbackException,
                );
            }

            throw $exception;
        }

        return $moved;
    }

    /** @param list<string> $suffixes */
    private function moveRequiredSidecars(string $fromBase, string $toBase, array $suffixes): void
    {
        $moved = [];

        try {
            foreach ($suffixes as $suffix) {
                $source = $fromBase.$suffix;
                $destination = $toBase.$suffix;
                if (! file_exists($source) || file_exists($destination) || ! rename($source, $destination)) {
                    throw new RuntimeException('تعذر إعادة ملفات SQLite الجانبية إلى موضعها الأصلي.');
                }

                $moved[] = $suffix;
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($moved) as $suffix) {
                if (! rename($toBase.$suffix, $fromBase.$suffix)) {
                    throw new RuntimeException(
                        'تعذر التراجع عن نقل جزئي لملفات SQLite الجانبية.',
                        previous: $exception,
                    );
                }
            }

            throw $exception;
        }
    }

    /** @param list<string> $originalSidecars */
    private function rollbackSwap(
        string $targetPath,
        string $rollbackPath,
        string $failedPath,
        array $originalSidecars,
    ): void {
        $failedSidecars = $this->moveExistingSidecars($targetPath, $failedPath);
        $failedMainMoved = false;
        $rollbackMainInstalled = false;

        try {
            if (file_exists($targetPath)) {
                if (! rename($targetPath, $failedPath)) {
                    throw new RuntimeException('تعذر عزل قاعدة البيانات المستعادة أثناء التراجع.');
                }
                $failedMainMoved = true;
            }

            if (! rename($rollbackPath, $targetPath)) {
                throw new RuntimeException('تعذر إعادة قاعدة البيانات السابقة أثناء التراجع.');
            }
            $rollbackMainInstalled = true;

            $this->moveRequiredSidecars($rollbackPath, $targetPath, $originalSidecars);
        } catch (Throwable $exception) {
            if ($rollbackMainInstalled) {
                if (! rename($targetPath, $rollbackPath)) {
                    throw new RuntimeException(
                        'قاعدة البيانات السابقة في المسار التشغيلي لكن ملفاتها الجانبية معزولة؛ يلزم تدخل تشغيلي.',
                        previous: $exception,
                    );
                }
            }

            if ($failedMainMoved) {
                if (file_exists($targetPath) || ! rename($failedPath, $targetPath)) {
                    throw new RuntimeException(
                        'فشل التراجع وتعذر إعادة قاعدة البيانات المستعادة إلى المسار التشغيلي.',
                        previous: $exception,
                    );
                }

                try {
                    $this->moveRequiredSidecars($failedPath, $targetPath, $failedSidecars);
                } catch (Throwable $sidecarException) {
                    if (! rename($targetPath, $failedPath)) {
                        throw new RuntimeException(
                            'تعذر إعادة تجميع قاعدة SQLite البديلة بعد فشل التراجع.',
                            previous: $sidecarException,
                        );
                    }

                    throw new RuntimeException(
                        'فشل التراجع؛ تم حفظ مجموعتي SQLite المعزولتين للتدخل التشغيلي.',
                        previous: $sidecarException,
                    );
                }
            } elseif (file_exists($targetPath) && $failedSidecars !== []) {
                try {
                    $this->moveRequiredSidecars($failedPath, $targetPath, $failedSidecars);
                } catch (Throwable $sidecarException) {
                    throw new RuntimeException(
                        'فشل التراجع وتعذر إعادة ملفات SQLite الجانبية إلى القاعدة التشغيلية.',
                        previous: $sidecarException,
                    );
                }
            }

            throw new RuntimeException(
                'فشل التراجع الآمن؛ تم الاحتفاظ بملفات الاستعادة والعزل للتدخل التشغيلي.',
                previous: $exception,
            );
        }

        $this->removeDatabaseSet($failedPath);
    }

    private function removeDatabaseSet(string $basePath): void
    {
        foreach ([$basePath, ...array_map(static fn (string $suffix): string => $basePath.$suffix, self::SIDECAR_SUFFIXES)] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    private function connect(string $path): PDO
    {
        return new PDO('sqlite:'.$path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function fingerprint(PDO $pdo): string
    {
        $statement = $pdo->query(
            "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY type, name",
        );
        if ($statement === false) {
            throw new RuntimeException('تعذر قراءة بصمة بنية قاعدة SQLite.');
        }
        $schema = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($schema === []) {
            throw new RuntimeException('لا تحتوي قاعدة SQLite بنية قابلة للتحقق.');
        }
        $encoded = json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded);
    }

    private function assertReadableFile(string $path): void
    {
        if (! is_file($path) || ! is_readable($path) || (filesize($path) ?: 0) <= 0) {
            throw new RuntimeException('ملف SQLite غير موجود أو فارغ أو غير قابل للقراءة.');
        }
    }

    private function samePath(string $first, string $second): bool
    {
        $firstReal = realpath($first);
        $secondReal = realpath($second);

        return $firstReal !== false && $secondReal !== false && $firstReal === $secondReal;
    }
}
