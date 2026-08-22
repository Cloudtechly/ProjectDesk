<?php

namespace App\Console\Commands;

use App\Services\FileInventoryLock;
use App\Services\OrphanedFileGarbageCollector;
use App\Services\RestoreWriteFence;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class PruneOrphanedFiles extends Command
{
    protected $signature = 'project-desk:prune-orphaned-files
        {--dry-run : Report eligible file objects without changing the database or private storage}';

    protected $description = 'Safely remove file objects that have no durable reference after the configured grace period';

    public function handle(
        OrphanedFileGarbageCollector $collector,
        RestoreWriteFence $fence,
        FileInventoryLock $fileInventoryLock,
    ): int {
        $retentionHours = (int) config('project-desk.uploads.orphan_retention_hours', 72);
        if ($retentionHours < 1 || $retentionHours > 8760) {
            $this->components->error('UPLOAD_ORPHAN_RETENTION_HOURS must be between 1 and 8760.');

            return self::FAILURE;
        }

        try {
            $restoreLock = $fence->acquireShared();
        } catch (RuntimeException) {
            $this->components->warn('A system restore is in progress; orphan-file pruning was skipped.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('project-desk:prune-orphaned-files', 3600);
        try {
            if (! $lock->get()) {
                $this->components->warn('Another orphan-file pruning run is active; this run was skipped.');

                return self::SUCCESS;
            }

            try {
                $result = $fileInventoryLock->block(
                    fn (): array => $collector->prune($retentionHours, (bool) $this->option('dry-run')),
                    1,
                );
            } catch (LockTimeoutException) {
                $this->components->warn('A complete backup is reading private files; orphan-file pruning was skipped.');

                return self::SUCCESS;
            }
            $this->components->info(sprintf(
                'Orphan-file retention complete: eligible=%d, pruned=%d, skipped=%d, failed=%d, stale_trash=%d.',
                $result['eligible'],
                $result['pruned'],
                $result['skipped'],
                $result['failed'],
                $result['trash_pruned'],
            ));

            return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Orphan-file retention failed; review the application log.');

            return self::FAILURE;
        } finally {
            $this->release($lock);
            $fence->release($restoreLock);
        }
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
