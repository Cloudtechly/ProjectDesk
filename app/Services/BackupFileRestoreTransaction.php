<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BackupFileRestoreTransaction
{
    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, string>  $filePaths
     * @param  list<array<string, mixed>>  $currentFiles
     * @return list<array<string, bool|int|string>>
     */
    public function stage(array $files, array $filePaths, array $currentFiles): array
    {
        $token = Str::lower((string) Str::uuid());
        $root = trim((string) config('project-desk.data_center.backup_directory', 'backups/project-desk'), '/')
            .'/restore-staging/'.$token;
        $transaction = [];
        $targetDestinations = [];

        try {
            foreach ($files as $index => $file) {
                $disk = (string) $file['disk'];
                $storageKey = (string) $file['storage_key'];
                $targetDestinations[$disk."\0".$storageKey] = true;
                $archivePath = (string) $file['archive_path'];
                $localPath = $filePaths[$archivePath] ?? null;
                if (! is_string($localPath) || ! is_file($localPath)) {
                    throw new RuntimeException('A verified project file is missing from restore staging.');
                }

                $stageKey = $root.'/'.str_pad((string) $index, 8, '0', STR_PAD_LEFT).'.incoming';
                $rollbackKey = $root.'/'.str_pad((string) $index, 8, '0', STR_PAD_LEFT).'.rollback';
                $transaction[] = [
                    'disk' => $disk,
                    'storage_key' => $storageKey,
                    'stage_key' => $stageKey,
                    'rollback_key' => $rollbackKey,
                    'root' => $root,
                    'checksum_sha256' => (string) $file['checksum_sha256'],
                    'size_bytes' => (int) $file['size_bytes'],
                    'had_original' => Storage::disk($disk)->exists($storageKey),
                    'delete_only' => false,
                    'state' => 'staged',
                ];
                $stream = fopen($localPath, 'rb');
                if (! is_resource($stream)) {
                    throw new RuntimeException('A verified project file could not be opened for restoration.');
                }
                try {
                    $stored = Storage::disk($disk)->put($stageKey, $stream);
                } finally {
                    fclose($stream);
                }
                if (! $stored) {
                    throw new RuntimeException('A project file could not be staged on its destination disk.');
                }
                $stagedChecksum = $this->storageChecksum($disk, $stageKey, (int) $file['size_bytes']);
                if (! hash_equals((string) $file['checksum_sha256'], $stagedChecksum)) {
                    throw new RuntimeException('A staged project file failed checksum verification.');
                }
            }

            foreach ($currentFiles as $index => $file) {
                $disk = (string) $file['disk'];
                $storageKey = (string) $file['storage_key'];
                if (isset($targetDestinations[$disk."\0".$storageKey])) {
                    continue;
                }
                if (! Storage::disk($disk)->exists($storageKey)) {
                    throw new RuntimeException('A current project file disappeared after the safety backup was created.');
                }
                $suffix = 'delete-'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);
                $transaction[] = [
                    'disk' => $disk,
                    'storage_key' => $storageKey,
                    'stage_key' => $root.'/'.$suffix.'.incoming',
                    'rollback_key' => $root.'/'.$suffix.'.rollback',
                    'root' => $root,
                    'checksum_sha256' => (string) $file['checksum_sha256'],
                    'size_bytes' => (int) $file['size_bytes'],
                    'had_original' => true,
                    'delete_only' => true,
                    'state' => 'staged',
                ];
            }
        } catch (Throwable $exception) {
            $this->cleanup($transaction);

            throw $exception;
        }

        return $transaction;
    }

    /** @param list<array<string, bool|int|string>> $transaction */
    public function commit(array &$transaction): void
    {
        foreach ($transaction as &$entry) {
            $storage = Storage::disk((string) $entry['disk']);
            if ((bool) $entry['had_original']) {
                if (! $storage->move((string) $entry['storage_key'], (string) $entry['rollback_key'])) {
                    throw new RuntimeException('An existing project file could not be isolated for rollback.');
                }
                $entry['state'] = 'original_moved';
            }
            if ((bool) $entry['delete_only']) {
                $entry['state'] = 'committed';

                continue;
            }
            if (! $storage->move((string) $entry['stage_key'], (string) $entry['storage_key'])) {
                throw new RuntimeException('A staged project file could not be committed.');
            }
            $entry['state'] = 'committed';
            $checksum = $this->storageChecksum(
                (string) $entry['disk'],
                (string) $entry['storage_key'],
                (int) $entry['size_bytes'],
            );
            if (! hash_equals((string) $entry['checksum_sha256'], $checksum)) {
                throw new RuntimeException('A restored project file failed its post-commit checksum.');
            }
        }
        unset($entry);
    }

    /** @param list<array<string, bool|int|string>> $transaction */
    public function rollback(array &$transaction): void
    {
        $failures = [];
        for ($index = count($transaction) - 1; $index >= 0; $index--) {
            $entry = &$transaction[$index];
            $storage = Storage::disk((string) $entry['disk']);
            $state = (string) $entry['state'];
            if ($state === 'committed' && $storage->exists((string) $entry['storage_key'])) {
                if (! $storage->delete((string) $entry['storage_key'])) {
                    $failures[] = (string) $entry['storage_key'];
                }
            }
            if (in_array($state, ['committed', 'original_moved'], true) && (bool) $entry['had_original']) {
                if (! $storage->exists((string) $entry['rollback_key'])
                    || ! $storage->move((string) $entry['rollback_key'], (string) $entry['storage_key'])) {
                    $failures[] = (string) $entry['storage_key'];
                }
            }
            if ($storage->exists((string) $entry['stage_key'])) {
                $storage->delete((string) $entry['stage_key']);
            }
            $entry['state'] = 'rolled_back';
            unset($entry);
        }
        if ($failures !== []) {
            throw new RuntimeException('One or more project files could not be rolled back automatically.');
        }
    }

    /** @param list<array<string, bool|int|string>> $transaction */
    public function cleanup(array $transaction): void
    {
        $roots = [];
        foreach ($transaction as $entry) {
            $disk = (string) $entry['disk'];
            $root = (string) $entry['root'];
            $roots[$disk."\0".$root] = [$disk, $root];
        }
        foreach ($roots as [$disk, $root]) {
            Storage::disk($disk)->deleteDirectory($root);
        }
    }

    private function storageChecksum(string $disk, string $key, int $expectedSize): string
    {
        $stream = Storage::disk($disk)->readStream($key);
        if (! is_resource($stream)) {
            throw new RuntimeException('A staged project file could not be read for checksum verification.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('A staged project file could not be read completely.');
                }
                if ($chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $expectedSize) {
                    throw new RuntimeException('A staged project file exceeds its declared size.');
                }
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }
        if ($size !== $expectedSize) {
            throw new RuntimeException('A staged project file size does not match its manifest.');
        }

        return hash_final($hash);
    }
}
