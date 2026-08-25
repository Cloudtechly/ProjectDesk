<?php

namespace App\Services;

use App\Models\Requirement;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequirementService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /** @param array<string, mixed> $validated */
    public function create(int $projectId, array $validated, User $actor): Requirement
    {
        return DB::transaction(function () use ($projectId, $validated, $actor): Requirement {
            Arr::forget($validated, 'lock_version');
            Arr::forget($validated, 'timeline_links_submitted');
            $timelineEntryIds = Arr::pull($validated, 'timeline_entry_ids', []);
            $requestedCode = trim((string) Arr::pull($validated, 'code', ''));
            $validated['project_id'] = $projectId;
            $validated['code'] = $requestedCode !== '' ? $requestedCode : 'PENDING-'.Str::uuid();

            $requirement = Requirement::query()->create($validated);
            $requirement->timelineEntries()->sync($timelineEntryIds);
            if ($requestedCode === '') {
                $requirement->forceFill([
                    'code' => 'REQ-'.str_pad((string) $requirement->id, 5, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }

            $this->activityLogger->record(
                $requirement,
                'requirement.created',
                $actor,
                after: $requirement->toArray(),
                request: request(),
            );

            return $requirement->refresh()->load(['status', 'owner', 'timelineEntries']);
        });
    }

    /** @param array<string, mixed> $validated */
    public function update(Requirement $requirement, array $validated, User $actor): Requirement
    {
        return DB::transaction(function () use ($requirement, $validated, $actor): Requirement {
            $locked = Requirement::query()->lockForUpdate()->findOrFail($requirement->id);
            $requestedVersion = (int) Arr::pull($validated, 'lock_version');
            $timelineLinksSubmitted = (bool) Arr::pull($validated, 'timeline_links_submitted', false);
            $timelineEntryIds = Arr::pull($validated, 'timeline_entry_ids', null);
            $this->assertCurrentVersion($locked, $requestedVersion);

            if ($locked->archived_at !== null || $locked->project->archived_at !== null) {
                throw ValidationException::withMessages(['requirement' => 'لا يمكن تعديل متطلب مؤرشف أو تابع لمشروع مؤرشف.']);
            }

            if (array_key_exists('code', $validated) && trim((string) $validated['code']) === '') {
                unset($validated['code']);
            }

            $before = $locked->toArray();
            $validated['lock_version'] = $locked->lock_version + 1;
            $locked->fill($validated)->save();
            if ($timelineLinksSubmitted || is_array($timelineEntryIds)) {
                $locked->timelineEntries()->sync(is_array($timelineEntryIds) ? $timelineEntryIds : []);
            }
            $this->activityLogger->record($locked, 'requirement.updated', $actor, $before, $locked->toArray(), request());

            return $locked->load(['status', 'owner', 'timelineEntries']);
        });
    }

    public function archive(Requirement $requirement, int $lockVersion, User $actor): Requirement
    {
        return DB::transaction(function () use ($requirement, $lockVersion, $actor): Requirement {
            $locked = Requirement::query()->lockForUpdate()->findOrFail($requirement->id);
            $this->assertCurrentVersion($locked, $lockVersion);

            if ($locked->archived_at !== null || $locked->project->archived_at !== null) {
                throw ValidationException::withMessages(['requirement' => 'المتطلب أو المشروع مؤرشف بالفعل.']);
            }

            $before = $locked->toArray();
            $locked->archived_at = Carbon::now();
            $locked->lock_version++;
            $locked->save();
            $this->activityLogger->record($locked, 'requirement.archived', $actor, $before, $locked->toArray(), request());

            return $locked;
        });
    }

    public function restore(Requirement $requirement, int $lockVersion, User $actor): Requirement
    {
        return DB::transaction(function () use ($requirement, $lockVersion, $actor): Requirement {
            $locked = Requirement::query()->lockForUpdate()->findOrFail($requirement->id);
            $this->assertCurrentVersion($locked, $lockVersion);

            if ($locked->archived_at === null) {
                throw ValidationException::withMessages(['requirement' => 'المتطلب نشط بالفعل ولا يحتاج إلى استعادة.']);
            }

            if ($locked->project->archived_at !== null) {
                throw ValidationException::withMessages(['requirement' => 'يجب استعادة المشروع أولاً قبل استعادة متطلباته.']);
            }

            $before = $locked->toArray();
            $locked->archived_at = null;
            $locked->lock_version++;
            $locked->save();
            $this->activityLogger->record($locked, 'requirement.restored', $actor, $before, $locked->toArray(), request());

            return $locked->load(['status', 'owner']);
        });
    }

    private function assertCurrentVersion(Requirement $requirement, int $requestedVersion): void
    {
        if ($requirement->lock_version !== $requestedVersion) {
            throw ValidationException::withMessages([
                'lock_version' => 'عُدّل المتطلب بواسطة مستخدم آخر. حدّث الصفحة ثم حاول مجدداً.',
            ]);
        }
    }
}
