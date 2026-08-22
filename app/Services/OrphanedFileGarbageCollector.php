<?php

namespace App\Services;

use App\Models\FileObject;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OrphanedFileGarbageCollector
{
    private const TRASH_ROOT = '.project-desk-retention-trash';

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /** @return array{eligible: int, pruned: int, skipped: int, failed: int, trash_pruned: int} */
    public function prune(int $retentionHours, bool $dryRun = false): array
    {
        if ($retentionHours < 1 || $retentionHours > 8760) {
            throw new RuntimeException('The orphan file retention period must be between 1 and 8760 hours.');
        }

        $cutoff = now()->subHours($retentionHours);
        $result = [
            'eligible' => 0,
            'pruned' => 0,
            'skipped' => 0,
            'failed' => 0,
            'trash_pruned' => $dryRun ? 0 : $this->pruneStaleTrash($cutoff),
        ];

        $this->candidates($cutoff)->chunkById(100, function ($files) use ($cutoff, $dryRun, &$result): void {
            foreach ($files as $candidate) {
                $result['eligible']++;
                if ($dryRun) {
                    continue;
                }

                try {
                    if ($this->pruneOne((int) $candidate->id, $cutoff)) {
                        $result['pruned']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $result['failed']++;
                }
            }
        });

        return $result;
    }

    /** @return Builder<FileObject> */
    private function candidates(CarbonInterface $cutoff): Builder
    {
        return FileObject::query()
            ->where('uploaded_at', '<=', $cutoff)
            ->whereDoesntHave('attachmentLinks')
            ->whereDoesntHave('requirementBookVersions')
            ->whereDoesntHave('meetingMinutes')
            ->whereDoesntHave('dataJobs')
            ->orderBy('id');
    }

    private function pruneOne(int $fileId, CarbonInterface $cutoff): bool
    {
        /** @var array{disk: string, original: string, trash: string}|null $moved */
        $moved = null;

        try {
            $pruned = DB::transaction(function () use ($fileId, $cutoff, &$moved): bool {
                $file = FileObject::query()->whereKey($fileId)->lockForUpdate()->first();
                if (! $file instanceof FileObject
                    || $file->uploaded_at->greaterThan($cutoff)
                    || $this->hasDurableReference($file->id)) {
                    return false;
                }

                $storage = Storage::disk($file->disk);
                if ($storage->exists($file->storage_key)) {
                    $trashKey = self::TRASH_ROOT.'/'.now()->format('YmdHi')
                        .'/'.$file->id.'-'.Str::uuid().'.blob';
                    if (! $storage->move($file->storage_key, $trashKey)) {
                        throw new RuntimeException("File object {$file->id} could not be moved to retention quarantine.");
                    }
                    $moved = [
                        'disk' => $file->disk,
                        'original' => $file->storage_key,
                        'trash' => $trashKey,
                    ];
                }

                $this->activityLogger->record(
                    $file,
                    'project_file.retention_pruned',
                    null,
                    after: [
                        'file_id' => $file->id,
                        'uploaded_at' => $file->uploaded_at->toIso8601String(),
                        'scan_status' => $file->scan_status,
                        'blob_present' => $moved !== null,
                    ],
                );
                $file->delete();

                return true;
            }, 3);
        } catch (Throwable $exception) {
            $this->restoreQuarantinedBlob($moved);

            throw $exception;
        }

        if ($pruned && $moved !== null) {
            try {
                $storage = Storage::disk($moved['disk']);
                if ($storage->exists($moved['trash']) && ! $storage->delete($moved['trash'])) {
                    report(new RuntimeException('A committed orphan-file quarantine blob could not be deleted.'));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $pruned;
    }

    private function hasDurableReference(int $fileId): bool
    {
        return DB::table('attachment_links')->where('file_object_id', $fileId)->exists()
            || DB::table('requirement_book_versions')->where('file_object_id', $fileId)->exists()
            || DB::table('meeting_minutes')->where('file_object_id', $fileId)->exists()
            || DB::table('data_jobs')->where('file_object_id', $fileId)->exists();
    }

    /** @param array{disk: string, original: string, trash: string}|null $moved */
    private function restoreQuarantinedBlob(?array $moved): void
    {
        if ($moved === null) {
            return;
        }

        try {
            $storage = Storage::disk($moved['disk']);
            if ($storage->exists($moved['trash'])
                && ! $storage->exists($moved['original'])
                && ! $storage->move($moved['trash'], $moved['original'])) {
                report(new RuntimeException('An orphan-file quarantine rollback could not restore the source blob.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function pruneStaleTrash(CarbonInterface $cutoff): int
    {
        $pruned = 0;
        $disks = FileObject::query()->distinct()->pluck('disk')
            ->push((string) config('project-desk.uploads.disk', 'local'))
            ->filter(fn (mixed $disk): bool => is_string($disk) && $disk !== '')
            ->unique();

        foreach ($disks as $diskName) {
            try {
                $storage = Storage::disk($diskName);
                foreach ($storage->allFiles(self::TRASH_ROOT) as $path) {
                    $fileId = $this->trashFileId($path);
                    $file = $fileId === null ? null : FileObject::query()->find($fileId);
                    if ($file instanceof FileObject && $file->disk !== $diskName) {
                        report(new RuntimeException(
                            "Retention quarantine disk mismatch for file object {$file->id}.",
                        ));

                        continue;
                    }
                    if ($file instanceof FileObject && $file->disk === $diskName) {
                        if (! $storage->exists($file->storage_key)
                            && $storage->move($path, $file->storage_key)) {
                            continue;
                        }

                        if (! $storage->exists($file->storage_key)) {
                            report(new RuntimeException(
                                "Retention quarantine could not restore file object {$file->id}.",
                            ));

                            continue;
                        }
                    }

                    if ($storage->lastModified($path) <= $cutoff->getTimestamp()
                        && $storage->delete($path)) {
                        $pruned++;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $pruned;
    }

    private function trashFileId(string $path): ?int
    {
        $basename = basename($path);
        if (preg_match('/\A([1-9][0-9]*)-[0-9a-f-]+\.blob\z/i', $basename, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }
}
