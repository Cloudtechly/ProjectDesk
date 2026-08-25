<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

class ProjectBackupBundleManager
{
    private const BUNDLE_FORMAT = 'project-desk-backup';

    private const BUNDLE_VERSION = 1;

    private const MAX_MANIFEST_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly BackupBundleCryptographer $cryptographer) {}

    /**
     * Create an encrypted, self-verifying Project Desk bundle from a consistent
     * SQLite snapshot. The snapshot itself is never modified.
     *
     * @return array<string, bool|int|string>
     */
    public function create(string $databasePath, string $destinationPath, bool $filesComplete = true): array
    {
        $this->assertReadableFile($databasePath);
        if (file_exists($destinationPath)) {
            throw new RuntimeException('The backup destination already exists.');
        }

        $directory = dirname($destinationPath);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The backup destination directory could not be created.');
        }

        $workingDirectory = $this->temporaryDirectory('project-desk-bundle-create-');

        try {
            $databaseInventory = $this->databaseInventory($databasePath);
            $inventory = $filesComplete
                ? $databaseInventory
                : ['files' => [], 'excluded_backup_file_ids' => []];
            $stagedFiles = $this->stageInventoryFiles($inventory['files'], $workingDirectory);
            $databaseChecksum = hash_file('sha256', $databasePath);
            $databaseSize = filesize($databasePath);
            if (! is_string($databaseChecksum) || ! is_int($databaseSize) || $databaseSize <= 0) {
                throw new RuntimeException('The SQLite snapshot checksum could not be calculated.');
            }

            $manifestFiles = [];
            foreach ($inventory['files'] as $file) {
                $manifestFiles[] = [
                    'id' => $file['id'],
                    'disk' => $file['disk'],
                    'storage_key' => $file['storage_key'],
                    'original_name' => $file['original_name'],
                    'mime_type' => $file['mime_type'],
                    'extension' => $file['extension'],
                    'size_bytes' => $file['size_bytes'],
                    'checksum_sha256' => $file['checksum_sha256'],
                    'scan_status' => $file['scan_status'],
                    'archive_path' => 'files/'.$file['checksum_sha256'].'.blob',
                ];
            }

            $manifest = [
                'format' => self::BUNDLE_FORMAT,
                'version' => self::BUNDLE_VERSION,
                'created_at' => now()->toIso8601String(),
                'files_complete' => $filesComplete,
                'legacy_database_only' => ! $filesComplete,
                'database' => [
                    'path' => 'database/database.sqlite',
                    'size_bytes' => $databaseSize,
                    'checksum_sha256' => $databaseChecksum,
                ],
                'files' => $manifestFiles,
                'excluded_backup_file_ids' => $inventory['excluded_backup_file_ids'],
                'inventory_sha256' => hash(
                    'sha256',
                    json_encode($manifestFiles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ),
            ];
            $manifestJson = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $zipPath = $workingDirectory.DIRECTORY_SEPARATOR.'payload.zip';
            $this->createZip($zipPath, $databasePath, $manifestJson, $stagedFiles);
            $encryption = $this->cryptographer->encrypt($zipPath, $destinationPath);

            // A newly created backup is not considered successful until it can
            // be authenticated, decrypted, and all manifest checksums pass.
            $verificationDirectory = $workingDirectory.DIRECTORY_SEPARATOR.'verification';
            mkdir($verificationDirectory, 0700, true);
            $prepared = $this->prepare($destinationPath, $verificationDirectory);
            if (! hash_equals($databaseChecksum, $prepared['manifest']['database']['checksum_sha256'])) {
                throw new RuntimeException('Post-create backup verification failed.');
            }

            $filesSize = (int) array_sum(array_column($manifestFiles, 'size_bytes'));

            return [
                'bundle_format' => self::BUNDLE_FORMAT,
                'bundle_version' => self::BUNDLE_VERSION,
                'encrypted' => true,
                'cipher' => $this->cryptographer->cipher(),
                'key_id' => $encryption['key_id'],
                'size_bytes' => $encryption['size_bytes'],
                'checksum_sha256' => $encryption['checksum_sha256'],
                'database_checksum_sha256' => $databaseChecksum,
                'database_size_bytes' => $databaseSize,
                'files_complete' => $filesComplete,
                'files_count' => count($manifestFiles),
                'files_size_bytes' => $filesSize,
                'manifest_checksum_sha256' => hash('sha256', $manifestJson),
                'verified_after_create' => true,
            ];
        } catch (Throwable $exception) {
            if (is_file($destinationPath)) {
                unlink($destinationPath);
            }

            throw $exception;
        } finally {
            $this->removeTemporaryDirectory($workingDirectory);
        }
    }

    public function isBundle(string $path): bool
    {
        return $this->cryptographer->isEncryptedBackup($path);
    }

    /**
     * Authenticate, decrypt, inspect, and materialize a bundle into a caller-
     * owned temporary directory. No archive path is ever used as an extraction
     * destination, preventing Zip Slip/path traversal.
     *
     * @return array{
     *   database_path: string,
     *   file_paths: array<string, string>,
     *   manifest: array<string, mixed>,
     *   encryption: array<string, int|string>,
     *   checksum_sha256: string,
     *   size_bytes: int
     * }
     */
    public function prepare(string $bundlePath, string $workingDirectory): array
    {
        $this->assertReadableFile($bundlePath);
        if (! is_dir($workingDirectory) && ! mkdir($workingDirectory, 0700, true) && ! is_dir($workingDirectory)) {
            throw new RuntimeException('The backup verification directory could not be created.');
        }

        $zipPath = $workingDirectory.DIRECTORY_SEPARATOR.'payload.zip';
        $encryption = $this->cryptographer->decrypt($bundlePath, $zipPath);
        $inspected = $this->inspectZip($zipPath, $workingDirectory.DIRECTORY_SEPARATOR.'contents');
        $this->assertManifestMatchesDatabase($inspected['database_path'], $inspected['manifest']);
        $checksum = hash_file('sha256', $bundlePath);
        $size = filesize($bundlePath);
        if (! is_string($checksum) || ! is_int($size) || $size <= 0) {
            throw new RuntimeException('The encrypted backup checksum could not be calculated.');
        }

        return [
            ...$inspected,
            'encryption' => $encryption,
            'checksum_sha256' => $checksum,
            'size_bytes' => $size,
        ];
    }

    /**
     * A database-only package has no embedded file payload. Before it can be
     * restored, every non-backup file record in its SQLite snapshot must
     * already exist on an allowed private disk with the exact recorded size
     * and checksum. This method is deliberately read-only.
     */
    public function assertDatabaseOnlyFilesAvailable(string $databasePath): int
    {
        $inventory = $this->databaseInventory($databasePath);

        foreach ($inventory['files'] as $file) {
            $disk = (string) $file['disk'];
            $storageKey = (string) $file['storage_key'];
            $expectedSize = (int) $file['size_bytes'];
            $expectedChecksum = (string) $file['checksum_sha256'];
            $stream = Storage::disk($disk)->readStream($storageKey);
            if (! is_resource($stream)) {
                throw new RuntimeException("File object {$file['id']} is missing from private storage.");
            }

            $hash = hash_init('sha256');
            $size = 0;
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException("File object {$file['id']} could not be read from private storage.");
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $size += strlen($chunk);
                    if ($size > $expectedSize) {
                        throw new RuntimeException("File object {$file['id']} exceeds its recorded size.");
                    }
                    hash_update($hash, $chunk);
                }
            } finally {
                fclose($stream);
            }

            if ($size !== $expectedSize || ! hash_equals($expectedChecksum, hash_final($hash))) {
                throw new RuntimeException("File object {$file['id']} does not match its recorded checksum.");
            }
        }

        return count($inventory['files']);
    }

    /**
     * @return array{files: list<array<string, int|string|null>>, excluded_backup_file_ids: list<int>}
     */
    private function databaseInventory(string $databasePath): array
    {
        $pdo = $this->connect($databasePath);
        $statement = $pdo->query(<<<'SQL'
            SELECT
                fo.id,
                fo.disk,
                fo.storage_key,
                fo.original_name,
                fo.mime_type,
                fo.extension,
                fo.size_bytes,
                fo.checksum_sha256,
                fo.scan_status,
                EXISTS (
                    SELECT 1 FROM data_jobs dj
                    WHERE dj.file_object_id = fo.id
                      AND dj.type IN ('backup', 'backup_upload', 'restore')
                ) AS is_backup_artifact,
                EXISTS (SELECT 1 FROM attachment_links al WHERE al.file_object_id = fo.id) AS has_attachment_link,
                EXISTS (SELECT 1 FROM meeting_minutes mm WHERE mm.file_object_id = fo.id) AS has_meeting_minutes,
                EXISTS (SELECT 1 FROM requirement_book_versions rbv WHERE rbv.file_object_id = fo.id) AS has_requirement_book
            FROM file_objects fo
            ORDER BY fo.id
        SQL);
        if ($statement === false) {
            throw new RuntimeException('The backup file inventory could not be read from SQLite.');
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $files = [];
        $excluded = [];
        $seenDestinations = [];
        $allowedDisks = $this->allowedFileDisks();
        $maximumEntries = max(1, (int) config('project-desk.data_center.backup_max_entries', 10000));

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $disk = (string) ($row['disk'] ?? '');
            $storageKey = (string) ($row['storage_key'] ?? '');
            $isBackupArtifact = (int) ($row['is_backup_artifact'] ?? 0) === 1
                && (int) ($row['has_attachment_link'] ?? 0) === 0
                && (int) ($row['has_meeting_minutes'] ?? 0) === 0
                && (int) ($row['has_requirement_book'] ?? 0) === 0
                && $this->isBackupStorageKey($storageKey);

            if ($isBackupArtifact) {
                $excluded[] = $id;

                continue;
            }
            if (count($files) >= $maximumEntries) {
                throw new RuntimeException('The backup contains more file records than the configured safety limit.');
            }
            if (! in_array($disk, $allowedDisks, true)) {
                throw new RuntimeException("File object {$id} uses a disk that is not enabled for complete backups.");
            }
            $this->assertSafeStorageKey($storageKey);
            if ($this->isBackupStorageKey($storageKey)) {
                throw new RuntimeException("File object {$id} conflicts with the reserved backup directory.");
            }
            $destination = $disk."\0".$storageKey;
            if (isset($seenDestinations[$destination])) {
                throw new RuntimeException('The SQLite snapshot contains duplicate file destinations.');
            }
            $seenDestinations[$destination] = true;

            $checksum = strtolower((string) ($row['checksum_sha256'] ?? ''));
            $size = (int) ($row['size_bytes'] ?? -1);
            $originalName = (string) ($row['original_name'] ?? '');
            $mimeType = (string) ($row['mime_type'] ?? 'application/octet-stream');
            $extension = $row['extension'] === null ? null : (string) $row['extension'];
            $scanStatus = (string) ($row['scan_status'] ?? 'pending');
            if ($id <= 0
                || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
                || $size < 0
                || $originalName === ''
                || strlen($originalName) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $originalName) === 1
                || strlen($mimeType) > 150
                || ($extension !== null && strlen($extension) > 20)
                || $scanStatus === '') {
                throw new RuntimeException("File object {$id} contains invalid backup metadata.");
            }

            $files[] = [
                'id' => $id,
                'disk' => $disk,
                'storage_key' => $storageKey,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'scan_status' => $scanStatus,
            ];
        }

        return ['files' => $files, 'excluded_backup_file_ids' => $excluded];
    }

    /**
     * @param  list<array<string, int|string|null>>  $files
     * @return array<string, string>
     */
    private function stageInventoryFiles(array $files, string $workingDirectory): array
    {
        $stagingDirectory = $workingDirectory.DIRECTORY_SEPARATOR.'files';
        if (! is_dir($stagingDirectory) && ! mkdir($stagingDirectory, 0700, true) && ! is_dir($stagingDirectory)) {
            throw new RuntimeException('The attachment staging directory could not be created.');
        }

        $staged = [];
        $totalBytes = 0;
        $maximumExpandedBytes = $this->maximumExpandedBytes();

        foreach ($files as $file) {
            $checksum = (string) $file['checksum_sha256'];
            $totalBytes += (int) $file['size_bytes'];
            if ($totalBytes > $maximumExpandedBytes) {
                throw new RuntimeException('The expanded backup size exceeds the configured safety limit.');
            }
            if (isset($staged[$checksum])) {
                continue;
            }

            $destination = $stagingDirectory.DIRECTORY_SEPARATOR.$checksum.'.blob';
            $stream = Storage::disk((string) $file['disk'])->readStream((string) $file['storage_key']);
            if (! is_resource($stream)) {
                throw new RuntimeException("File object {$file['id']} is missing from private storage.");
            }

            try {
                $result = $this->copyStreamToPath($stream, $destination, (int) $file['size_bytes']);
            } finally {
                fclose($stream);
            }
            if ($result['size_bytes'] !== (int) $file['size_bytes']
                || ! hash_equals($checksum, $result['checksum_sha256'])) {
                throw new RuntimeException("File object {$file['id']} failed checksum verification.");
            }
            $staged[$checksum] = $destination;
        }

        return $staged;
    }

    /** @param array<string, string> $stagedFiles */
    private function createZip(
        string $zipPath,
        string $databasePath,
        string $manifestJson,
        array $stagedFiles,
    ): void {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL);
        if ($opened !== true) {
            throw new RuntimeException('The backup archive could not be created.');
        }

        try {
            if (! $zip->addFromString('manifest.json', $manifestJson)
                || ! $zip->addFile($databasePath, 'database/database.sqlite')) {
                throw new RuntimeException('The backup database could not be added to the archive.');
            }
            $zip->setCompressionName('manifest.json', ZipArchive::CM_DEFLATE, 9);
            $zip->setCompressionName('database/database.sqlite', ZipArchive::CM_DEFLATE, 6);

            foreach ($stagedFiles as $checksum => $path) {
                $archivePath = 'files/'.$checksum.'.blob';
                if (! $zip->addFile($path, $archivePath)) {
                    throw new RuntimeException('A private project file could not be added to the backup archive.');
                }
                $zip->setCompressionName($archivePath, ZipArchive::CM_DEFLATE, 6);
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('The backup archive could not be finalized.');
            }
        }
    }

    /**
     * @return array{key_id: string, checksum_sha256: string, size_bytes: int}
     */
    /**
     * @return array{database_path: string, file_paths: array<string, string>, manifest: array<string, mixed>}
     */
    private function inspectZip(string $zipPath, string $contentsDirectory): array
    {
        if (! is_dir($contentsDirectory) && ! mkdir($contentsDirectory, 0700, true) && ! is_dir($contentsDirectory)) {
            throw new RuntimeException('The backup inspection directory could not be created.');
        }
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new RuntimeException('The decrypted backup archive is invalid.');
        }

        try {
            $maximumEntries = max(1, (int) config('project-desk.data_center.backup_max_entries', 10000));
            if ($zip->numFiles < 2 || $zip->numFiles > $maximumEntries + 2) {
                throw new RuntimeException('The backup archive contains an invalid number of entries.');
            }
            $entries = [];
            $expandedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat)) {
                    throw new RuntimeException('The backup archive directory is invalid.');
                }
                $name = $stat['name'];
                if (isset($entries[$name]) || ! $this->isSafeArchivePath($name)) {
                    throw new RuntimeException('The backup archive contains a duplicate or unsafe path.');
                }
                $entries[$name] = $stat;
                $expandedBytes += $stat['size'];
                if ($expandedBytes > $this->maximumExpandedBytes()) {
                    throw new RuntimeException('The expanded backup archive exceeds the configured safety limit.');
                }
            }
            if (! isset($entries['manifest.json'], $entries['database/database.sqlite'])
                || $entries['manifest.json']['size'] <= 0
                || $entries['manifest.json']['size'] > self::MAX_MANIFEST_BYTES) {
                throw new RuntimeException('The backup manifest or database entry is missing.');
            }

            $manifestJson = $zip->getFromName('manifest.json', 0, ZipArchive::FL_UNCHANGED);
            if (! is_string($manifestJson)) {
                throw new RuntimeException('The backup manifest could not be read.');
            }
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            $manifest = $this->validateManifest($manifest);
            $allowedEntries = [
                'manifest.json' => true,
                'database/database.sqlite' => true,
            ];
            foreach ($manifest['files'] as $file) {
                $allowedEntries[$file['archive_path']] = true;
            }
            if (array_diff_key($entries, $allowedEntries) !== [] || array_diff_key($allowedEntries, $entries) !== []) {
                throw new RuntimeException('The backup archive entries do not match its manifest.');
            }

            $databaseDirectory = $contentsDirectory.DIRECTORY_SEPARATOR.'database';
            mkdir($databaseDirectory, 0700, true);
            $databasePath = $databaseDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
            $this->copyZipEntry(
                $zip,
                'database/database.sqlite',
                $databasePath,
                (int) $manifest['database']['size_bytes'],
                (string) $manifest['database']['checksum_sha256'],
            );

            $filesDirectory = $contentsDirectory.DIRECTORY_SEPARATOR.'files';
            mkdir($filesDirectory, 0700, true);
            $filePaths = [];
            foreach ($manifest['files'] as $file) {
                $archivePath = $file['archive_path'];
                if (isset($filePaths[$archivePath])) {
                    continue;
                }
                $localPath = $filesDirectory.DIRECTORY_SEPARATOR.$file['checksum_sha256'].'.blob';
                $this->copyZipEntry(
                    $zip,
                    $archivePath,
                    $localPath,
                    (int) $file['size_bytes'],
                    (string) $file['checksum_sha256'],
                );
                $filePaths[$archivePath] = $localPath;
            }

            return [
                'database_path' => $databasePath,
                'file_paths' => $filePaths,
                'manifest' => $manifest,
            ];
        } catch (JsonException $exception) {
            throw new RuntimeException('The backup manifest is not valid JSON.', previous: $exception);
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, mixed> */
    private function validateManifest(mixed $manifest): array
    {
        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== self::BUNDLE_FORMAT
            || ($manifest['version'] ?? null) !== self::BUNDLE_VERSION
            || ! is_bool($manifest['files_complete'] ?? null)
            || ! is_bool($manifest['legacy_database_only'] ?? null)
            || ! is_array($manifest['database'] ?? null)
            || ($manifest['database']['path'] ?? null) !== 'database/database.sqlite'
            || ! is_int($manifest['database']['size_bytes'] ?? null)
            || $manifest['database']['size_bytes'] <= 0
            || ! is_string($manifest['database']['checksum_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $manifest['database']['checksum_sha256']) !== 1
            || ! is_array($manifest['files'] ?? null)
            || ! is_array($manifest['excluded_backup_file_ids'] ?? null)
            || ! is_string($manifest['inventory_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $manifest['inventory_sha256']) !== 1) {
            throw new RuntimeException('The Project Desk backup manifest is invalid.');
        }
        if ($manifest['legacy_database_only'] === $manifest['files_complete']) {
            throw new RuntimeException('The backup completeness flags are inconsistent.');
        }

        $maximumEntries = max(1, (int) config('project-desk.data_center.backup_max_entries', 10000));
        if (count($manifest['files']) > $maximumEntries) {
            throw new RuntimeException('The backup manifest contains too many file records.');
        }
        $allowedDisks = $this->allowedFileDisks();
        $seenIds = [];
        $seenDestinations = [];
        $normalizedFiles = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file)
                || ! is_int($file['id'] ?? null)
                || $file['id'] <= 0
                || ! is_string($file['disk'] ?? null)
                || ! in_array($file['disk'], $allowedDisks, true)
                || ! is_string($file['storage_key'] ?? null)
                || ! is_string($file['original_name'] ?? null)
                || $file['original_name'] === ''
                || strlen($file['original_name']) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $file['original_name']) === 1
                || ! is_string($file['mime_type'] ?? null)
                || strlen($file['mime_type']) > 150
                || ! (is_string($file['extension'] ?? null) || ($file['extension'] ?? null) === null)
                || (is_string($file['extension'] ?? null) && strlen($file['extension']) > 20)
                || ! is_int($file['size_bytes'] ?? null)
                || $file['size_bytes'] < 0
                || ! is_string($file['checksum_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $file['checksum_sha256']) !== 1
                || ! is_string($file['scan_status'] ?? null)
                || $file['scan_status'] === ''
                || ! is_string($file['archive_path'] ?? null)
                || $file['archive_path'] !== 'files/'.$file['checksum_sha256'].'.blob') {
                throw new RuntimeException('A backup manifest file record is invalid.');
            }
            $this->assertSafeStorageKey($file['storage_key']);
            if ($this->isBackupStorageKey($file['storage_key'])) {
                throw new RuntimeException('A backup manifest file targets the reserved backup directory.');
            }
            $destination = $file['disk']."\0".$file['storage_key'];
            if (isset($seenIds[$file['id']]) || isset($seenDestinations[$destination])) {
                throw new RuntimeException('The backup manifest contains duplicate file identities or destinations.');
            }
            $seenIds[$file['id']] = true;
            $seenDestinations[$destination] = true;
            $normalizedFiles[] = $file;
        }
        $inventoryJson = json_encode(
            $normalizedFiles,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (! hash_equals($manifest['inventory_sha256'], hash('sha256', $inventoryJson))) {
            throw new RuntimeException('The backup inventory checksum is invalid.');
        }

        $excluded = [];
        foreach ($manifest['excluded_backup_file_ids'] as $id) {
            if (! is_int($id) || $id <= 0 || isset($excluded[$id]) || isset($seenIds[$id])) {
                throw new RuntimeException('The excluded backup file inventory is invalid.');
            }
            $excluded[$id] = true;
        }
        if ($manifest['files_complete'] === false && ($normalizedFiles !== [] || $excluded !== [])) {
            throw new RuntimeException('A legacy database-only backup cannot contain file inventory entries.');
        }

        $manifest['files'] = $normalizedFiles;
        $manifest['excluded_backup_file_ids'] = array_keys($excluded);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifestMatchesDatabase(string $databasePath, array $manifest): void
    {
        $expected = $this->databaseInventory($databasePath);
        if ($manifest['files_complete'] === false) {
            return;
        }

        $manifestFiles = [];
        foreach ($manifest['files'] as $file) {
            $manifestFiles[$file['id']] = $file;
        }
        if (array_values($manifest['excluded_backup_file_ids']) !== $expected['excluded_backup_file_ids']
            || count($manifestFiles) !== count($expected['files'])) {
            throw new RuntimeException('The backup manifest does not cover the SQLite file inventory.');
        }

        foreach ($expected['files'] as $file) {
            $actual = $manifestFiles[$file['id']] ?? null;
            if (! is_array($actual)) {
                throw new RuntimeException('A SQLite file record is missing from the backup manifest.');
            }
            foreach (['disk', 'storage_key', 'original_name', 'mime_type', 'extension', 'size_bytes', 'checksum_sha256', 'scan_status'] as $field) {
                if (($actual[$field] ?? null) !== $file[$field]) {
                    throw new RuntimeException('The backup manifest file metadata does not match SQLite.');
                }
            }
        }
    }

    private function copyZipEntry(
        ZipArchive $zip,
        string $entry,
        string $destination,
        int $expectedSize,
        string $expectedChecksum,
    ): void {
        $stream = $zip->getStream($entry);
        if (! is_resource($stream)) {
            throw new RuntimeException('A backup archive entry could not be opened.');
        }
        try {
            $result = $this->copyStreamToPath($stream, $destination, $expectedSize);
        } finally {
            fclose($stream);
        }
        if ($result['size_bytes'] !== $expectedSize
            || ! hash_equals($expectedChecksum, $result['checksum_sha256'])) {
            throw new RuntimeException('A backup archive entry failed checksum verification.');
        }
    }

    /**
     * @param  resource  $source
     * @return array{size_bytes: int, checksum_sha256: string}
     */
    private function copyStreamToPath($source, string $destination, int $expectedMaximum): array
    {
        $target = fopen($destination, 'xb');
        if (! is_resource($target)) {
            throw new RuntimeException('A backup staging file could not be created.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('A backup source stream could not be read.');
                }
                if ($chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $expectedMaximum) {
                    throw new RuntimeException('A backup entry is larger than its manifest declaration.');
                }
                hash_update($hash, $chunk);
                $this->writeAll($target, $chunk);
            }
            fflush($target);
        } finally {
            fclose($target);
        }

        return ['size_bytes' => $size, 'checksum_sha256' => hash_final($hash)];
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (! is_int($written) || $written <= 0) {
                throw new RuntimeException('A backup stream could not be written completely.');
            }
            $offset += $written;
        }
    }

    private function isSafeArchivePath(string $path): bool
    {
        if ($path === 'manifest.json' || $path === 'database/database.sqlite') {
            return true;
        }

        return preg_match('/\Afiles\/[a-f0-9]{64}\.blob\z/', $path) === 1;
    }

    private function assertSafeStorageKey(string $key): void
    {
        if ($key === ''
            || strlen($key) > 1024
            || str_contains($key, '\\')
            || str_starts_with($key, '/')
            || preg_match('/\A[A-Za-z]:/', $key) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new RuntimeException('A backup storage key is unsafe.');
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('A backup storage key contains path traversal.');
            }
        }
    }

    private function isBackupStorageKey(string $key): bool
    {
        $directory = trim((string) config('project-desk.data_center.backup_directory', 'backups/project-desk'), '/');

        return $key === $directory || str_starts_with($key, $directory.'/');
    }

    /** @return list<string> */
    private function allowedFileDisks(): array
    {
        $disks = config('project-desk.data_center.backup_file_disks', ['local']);
        if (! is_array($disks)) {
            throw new RuntimeException('The complete-backup disk allowlist is invalid.');
        }
        $configured = array_keys((array) config('filesystems.disks', []));
        $result = [];
        foreach ($disks as $disk) {
            if (! is_string($disk) || $disk === '' || ! in_array($disk, $configured, true)) {
                throw new RuntimeException('A complete-backup disk is not configured.');
            }
            if (config("filesystems.disks.{$disk}.visibility") === 'public') {
                throw new RuntimeException('Complete backups cannot target or restore project files on a public disk.');
            }
            $result[] = $disk;
        }
        if ($result === []) {
            throw new RuntimeException('At least one private file disk must be enabled for complete backups.');
        }

        return array_values(array_unique($result));
    }

    private function maximumExpandedBytes(): int
    {
        return max(
            1024 * 1024,
            (int) config('project-desk.data_center.backup_max_expanded_kilobytes', 2 * 1024 * 1024) * 1024,
        );
    }

    private function connect(string $path): PDO
    {
        return new PDO('sqlite:'.$path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function assertReadableFile(string $path): void
    {
        if (! is_file($path) || ! is_readable($path) || (filesize($path) ?: 0) <= 0) {
            throw new RuntimeException('The backup source file is missing, empty, or unreadable.');
        }
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
        $real = realpath($directory);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($real === false
            || $temporaryRoot === false
            || ! str_starts_with($real, rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'project-desk-bundle-create-')) {
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
