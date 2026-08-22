<?php

namespace App\Services;

use App\Models\AttachmentLink;
use App\Models\Project;
use App\Models\RequirementBook;
use App\Models\RequirementBookVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RequirementBookService
{
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @param array<string, mixed> $validated */
    public function addVersion(Project $project, array $validated, User $actor): RequirementBookVersion
    {
        $upload = Arr::pull($validated, 'file');
        if (! $upload instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'ملف كراسة المتطلبات مطلوب.']);
        }

        $file = $this->projectFileService->storeForProject($upload, $project, $actor, false);

        try {
            $version = DB::transaction(function () use ($project, $validated, $actor, $file): RequirementBookVersion {
                $book = RequirementBook::query()
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->first();

                if (! $book instanceof RequirementBook) {
                    $book = RequirementBook::query()->create([
                        'project_id' => $project->id,
                        'title' => (string) $validated['title'],
                    ]);
                } else {
                    $book->update(['title' => (string) $validated['title']]);
                }

                $requestedNumber = $validated['version_number'] ?? null;
                $versionNumber = is_int($requestedNumber)
                    ? $requestedNumber
                    : ((int) $book->versions()->max('version_number')) + 1;

                if ($book->versions()->where('version_number', $versionNumber)->exists()) {
                    throw ValidationException::withMessages([
                        'version_number' => 'رقم الإصدار مستخدم مسبقاً لهذه الكراسة.',
                    ]);
                }

                $hasCurrent = $book->versions()
                    ->whereNull('archived_at')
                    ->where('is_current', true)
                    ->exists();
                $isCurrent = (bool) ($validated['is_current'] ?? false) || ! $hasCurrent;
                if ($isCurrent) {
                    $book->versions()->whereNull('archived_at')->where('is_current', true)->update([
                        'is_current' => false,
                        'lock_version' => DB::raw('lock_version + 1'),
                    ]);
                }

                $version = RequirementBookVersion::query()->create([
                    'requirement_book_id' => $book->id,
                    'title' => (string) $validated['title'],
                    'version_number' => $versionNumber,
                    'status' => (string) ($validated['status'] ?? 'draft'),
                    'file_object_id' => $file->id,
                    'note' => $validated['note'] ?? null,
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                    'is_current' => $isCurrent,
                    'lock_version' => 1,
                ]);

                AttachmentLink::query()
                    ->where('file_object_id', $file->id)
                    ->where('project_id', $project->id)
                    ->whereNull('task_id')
                    ->whereNull('requirement_id')
                    ->whereNull('requirement_book_version_id')
                    ->whereNull('meeting_minutes_id')
                    ->whereNull('archived_at')
                    ->limit(1)
                    ->update([
                        'requirement_book_version_id' => $version->id,
                        'archived_at' => null,
                    ]);

                return $version->load(['fileObject.uploader:id,name', 'uploader:id,name', 'requirementBook']);
            });
        } catch (Throwable $exception) {
            $this->projectFileService->discardNewUpload($file);

            throw $exception;
        }

        $this->activityLogger->record(
            $version,
            'requirement_book.version_uploaded',
            $actor,
            after: $this->versionData($version),
            request: request(),
        );

        return $version;
    }

    /** @param array<string, mixed> $validated */
    public function updateVersion(RequirementBookVersion $version, array $validated, User $actor): RequirementBookVersion
    {
        return DB::transaction(function () use ($version, $validated, $actor): RequirementBookVersion {
            $locked = RequirementBookVersion::query()
                ->with('requirementBook')
                ->lockForUpdate()
                ->findOrFail($version->id);
            $this->assertCurrentLock($locked, (int) $validated['lock_version']);
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['version' => 'لا يمكن تعديل إصدار مؤرشف.']);
            }

            $before = $this->versionData($locked->load(['fileObject.uploader:id,name', 'uploader:id,name']));
            $becomesCurrent = (bool) ($validated['is_current'] ?? false);
            if (array_key_exists('is_current', $validated) && ! $becomesCurrent && $locked->is_current) {
                throw ValidationException::withMessages([
                    'is_current' => 'عيّن إصداراً آخر كحالي بدلاً من إلغاء الإصدار الحالي مباشرة.',
                ]);
            }

            if ($becomesCurrent && ! $locked->is_current) {
                $locked->requirementBook->versions()
                    ->whereNull('archived_at')
                    ->whereKeyNot($locked->id)
                    ->where('is_current', true)
                    ->update([
                        'is_current' => false,
                        'lock_version' => DB::raw('lock_version + 1'),
                    ]);
            }

            $locked->fill(Arr::only($validated, ['title', 'status', 'note', 'is_current']));
            $locked->lock_version++;
            $locked->save();
            if ($locked->is_current) {
                $locked->requirementBook->update(['title' => $locked->title ?? $locked->requirementBook->title]);
            }

            $locked->load(['fileObject.uploader:id,name', 'uploader:id,name', 'requirementBook']);
            $this->activityLogger->record(
                $locked,
                'requirement_book.version_updated',
                $actor,
                $before,
                $this->versionData($locked),
                request(),
            );

            return $locked;
        });
    }

    public function makeCurrent(RequirementBookVersion $version, int $expectedLock, User $actor): RequirementBookVersion
    {
        return DB::transaction(function () use ($version, $expectedLock, $actor): RequirementBookVersion {
            $locked = RequirementBookVersion::query()
                ->with('requirementBook')
                ->lockForUpdate()
                ->findOrFail($version->id);
            $this->assertCurrentLock($locked, $expectedLock);
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['version' => 'لا يمكن تعيين إصدار مؤرشف كإصدار حالي.']);
            }

            $before = $this->versionData($locked->load(['fileObject.uploader:id,name', 'uploader:id,name']));
            $locked->requirementBook->versions()
                ->whereNull('archived_at')
                ->whereKeyNot($locked->id)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'lock_version' => DB::raw('lock_version + 1'),
                ]);
            $locked->forceFill(['is_current' => true, 'lock_version' => $locked->lock_version + 1])->save();
            $locked->requirementBook->update(['title' => $locked->title ?? $locked->requirementBook->title]);
            $locked->load(['fileObject.uploader:id,name', 'uploader:id,name', 'requirementBook']);

            $this->activityLogger->record(
                $locked,
                'requirement_book.current_version_changed',
                $actor,
                $before,
                $this->versionData($locked),
                request(),
            );

            return $locked;
        });
    }

    public function archiveVersion(RequirementBookVersion $version, int $expectedLock, User $actor): RequirementBookVersion
    {
        return DB::transaction(function () use ($version, $expectedLock, $actor): RequirementBookVersion {
            $locked = RequirementBookVersion::query()
                ->with('requirementBook')
                ->lockForUpdate()
                ->findOrFail($version->id);
            $this->assertCurrentLock($locked, $expectedLock);
            if ($locked->archived_at !== null) {
                return $locked->load(['fileObject.uploader:id,name', 'uploader:id,name', 'requirementBook']);
            }

            $before = $this->versionData($locked->load(['fileObject.uploader:id,name', 'uploader:id,name']));
            $wasCurrent = $locked->is_current;
            $archivedAt = now();
            $locked->forceFill([
                'is_current' => false,
                'archived_at' => $archivedAt,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            AttachmentLink::query()
                ->where('requirement_book_version_id', $locked->id)
                ->where('file_object_id', $locked->file_object_id)
                ->update(['archived_at' => $archivedAt]);

            if ($wasCurrent) {
                $replacement = $locked->requirementBook->versions()
                    ->whereNull('archived_at')
                    ->whereKeyNot($locked->id)
                    ->orderByDesc('version_number')
                    ->lockForUpdate()
                    ->first();
                if ($replacement instanceof RequirementBookVersion) {
                    $replacement->forceFill([
                        'is_current' => true,
                        'lock_version' => $replacement->lock_version + 1,
                    ])->save();
                    $locked->requirementBook->update(['title' => $replacement->title ?? $locked->requirementBook->title]);
                }
            }

            $locked->load(['fileObject.uploader:id,name', 'uploader:id,name', 'requirementBook']);
            $this->activityLogger->record(
                $locked,
                'requirement_book.version_archived',
                $actor,
                $before,
                $this->versionData($locked),
                request(),
            );

            return $locked;
        });
    }

    public function restoreVersion(RequirementBookVersion $version, int $expectedLock, User $actor): RequirementBookVersion
    {
        return DB::transaction(function () use ($version, $expectedLock, $actor): RequirementBookVersion {
            $locked = RequirementBookVersion::query()
                ->with('requirementBook')
                ->lockForUpdate()
                ->findOrFail($version->id);
            $this->assertCurrentLock($locked, $expectedLock);
            if ($locked->archived_at === null) {
                return $locked->load(['fileObject.uploader:id,name', 'uploader:id,name', 'requirementBook']);
            }

            $before = $this->versionData($locked->load(['fileObject.uploader:id,name', 'uploader:id,name']));
            $hasCurrent = $locked->requirementBook->versions()
                ->whereNull('archived_at')
                ->where('is_current', true)
                ->lockForUpdate()
                ->exists();
            $locked->forceFill([
                'is_current' => ! $hasCurrent,
                'archived_at' => null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            AttachmentLink::query()
                ->where('requirement_book_version_id', $locked->id)
                ->where('file_object_id', $locked->file_object_id)
                ->update(['archived_at' => null]);
            if (! $hasCurrent) {
                $locked->requirementBook->update(['title' => $locked->title]);
            }

            $locked->load(['fileObject.uploader:id,name', 'uploader:id,name', 'requirementBook']);
            $this->activityLogger->record(
                $locked,
                'requirement_book.version_restored',
                $actor,
                $before,
                $this->versionData($locked),
                request(),
            );

            return $locked;
        });
    }

    /** @return array{id: int|null, project_id: int, title: string|null, current_version_id: int|null, versions: list<array<string, mixed>>} */
    public function bookData(Project $project, bool $includeArchived = false): array
    {
        $book = RequirementBook::query()->where('project_id', $project->id)->first();
        if (! $book instanceof RequirementBook) {
            return [
                'id' => null,
                'project_id' => $project->id,
                'title' => null,
                'current_version_id' => null,
                'versions' => [],
            ];
        }

        $versions = $book->versions()
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
            ->with(['fileObject.uploader:id,name', 'uploader:id,name'])
            ->orderByDesc('version_number')
            ->get();

        $currentId = $versions->firstWhere('is_current', true)?->id;

        return [
            'id' => $book->id,
            'project_id' => $project->id,
            'title' => $book->title,
            'current_version_id' => $currentId,
            'versions' => array_values(
                $versions->map(fn (RequirementBookVersion $version): array => $this->versionData($version))->all(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function versionData(RequirementBookVersion $version): array
    {
        $version->loadMissing(['fileObject.uploader:id,name', 'uploader:id,name']);

        return [
            'id' => $version->id,
            'title' => $version->title,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'note' => $version->note,
            'is_current' => $version->is_current,
            'lock_version' => $version->lock_version,
            'uploaded_at' => $version->uploaded_at->toIso8601String(),
            'archived_at' => $version->archived_at?->toIso8601String(),
            'uploader' => [
                'id' => $version->uploader->id,
                'name' => $version->uploader->name,
            ],
            'file' => $this->projectFileService->metadata($version->fileObject),
        ];
    }

    private function assertCurrentLock(RequirementBookVersion $version, int $expectedLock): void
    {
        if ($version->lock_version !== $expectedLock) {
            throw ValidationException::withMessages([
                'lock_version' => 'تم تعديل هذا الإصدار في جلسة أخرى. حدّث الصفحة ثم أعد المحاولة.',
            ]);
        }
    }
}
