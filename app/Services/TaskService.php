<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAssignmentEvent;
use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /** @param array<string, mixed> $validated */
    public function create(array $validated, User $actor): Task
    {
        return DB::transaction(function () use ($validated, $actor): Task {
            $requirementIds = Arr::pull($validated, 'requirement_ids', []);
            $assignmentNote = Arr::pull($validated, 'assignment_note');
            Arr::forget($validated, 'lock_version');

            $validated['code'] = 'PENDING-'.Str::uuid();
            $this->normalizeAssignment($validated);
            $this->normalizeCompletion($validated);

            $task = Task::query()->create($validated);
            $task->forceFill(['code' => 'TSK-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
            $task->requirements()->sync($requirementIds);

            if ($task->assignee_id !== null) {
                TaskAssignmentEvent::query()->create([
                    'task_id' => $task->id,
                    'from_user_id' => null,
                    'to_user_id' => $task->assignee_id,
                    'recorded_by' => $actor->id,
                    'assigned_at' => $task->assigned_at,
                    'recorded_at' => Date::now(),
                    'note' => $assignmentNote,
                ]);
            }

            $this->activityLogger->record($task, 'task.created', $actor, after: $task->toArray(), request: request());

            return $task->load(['project', 'status', 'assignee', 'requirements', 'assignmentEvents']);
        });
    }

    /** @param array<string, mixed> $validated */
    public function update(Task $task, array $validated, User $actor): Task
    {
        return DB::transaction(function () use ($task, $validated, $actor): Task {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $requestedVersion = (int) Arr::pull($validated, 'lock_version');

            if ($requestedVersion !== $lockedTask->lock_version) {
                throw ValidationException::withMessages([
                    'lock_version' => 'عُدلت المهمة بواسطة مستخدم آخر. حدّث الصفحة ثم حاول مجدداً.',
                ]);
            }

            $requirementIds = Arr::pull($validated, 'requirement_ids', []);
            $assignmentNote = Arr::pull($validated, 'assignment_note');
            $before = $lockedTask->toArray();
            $previousAssignee = $lockedTask->assignee_id;

            $this->normalizeAssignment($validated, $lockedTask);
            $this->normalizeCompletion($validated, $lockedTask);
            $validated['lock_version'] = $lockedTask->lock_version + 1;

            $lockedTask->fill($validated)->save();
            $lockedTask->requirements()->sync($requirementIds);

            if ($previousAssignee !== $lockedTask->assignee_id) {
                TaskAssignmentEvent::query()->create([
                    'task_id' => $lockedTask->id,
                    'from_user_id' => $previousAssignee,
                    'to_user_id' => $lockedTask->assignee_id,
                    'recorded_by' => $actor->id,
                    'assigned_at' => $lockedTask->assigned_at ?? Date::now(),
                    'recorded_at' => Date::now(),
                    'note' => $assignmentNote,
                ]);
            }

            $this->activityLogger->record($lockedTask, 'task.updated', $actor, $before, $lockedTask->toArray(), request());

            return $lockedTask->load(['project', 'status', 'assignee', 'requirements', 'assignmentEvents']);
        });
    }

    public function updateStatus(Task $task, int $statusId, int $lockVersion, User $actor): Task
    {
        return DB::transaction(function () use ($task, $statusId, $lockVersion, $actor): Task {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($lockedTask->lock_version !== $lockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => 'عُدلت المهمة بواسطة مستخدم آخر. حدّث الصفحة ثم حاول مجدداً.',
                ]);
            }

            $status = WorkflowStatus::query()
                ->where('entity_type', 'task')
                ->where('is_active', true)
                ->findOrFail($statusId);
            $before = $lockedTask->toArray();
            $lockedTask->status_id = $status->id;
            $lockedTask->completed_at = $status->semantic === 'done'
                ? ($lockedTask->completed_at ?? Carbon::now())
                : null;
            $lockedTask->lock_version++;
            $lockedTask->save();

            $this->activityLogger->record($lockedTask, 'task.status_changed', $actor, $before, $lockedTask->toArray(), request());

            return $lockedTask->load(['project', 'status', 'assignee']);
        });
    }

    public function archive(Task $task, int $lockVersion, User $actor): Task
    {
        return $this->setArchivedState($task, $lockVersion, $actor, true);
    }

    public function restore(Task $task, int $lockVersion, User $actor): Task
    {
        return $this->setArchivedState($task, $lockVersion, $actor, false);
    }

    private function setArchivedState(Task $task, int $lockVersion, User $actor, bool $archive): Task
    {
        return DB::transaction(function () use ($task, $lockVersion, $actor, $archive): Task {
            $lockedTask = Task::query()->with('project')->lockForUpdate()->findOrFail($task->id);
            if ($lockedTask->lock_version !== $lockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => 'عُدلت المهمة بواسطة مستخدم آخر. حدّث الصفحة ثم حاول مجدداً.',
                ]);
            }

            if ($lockedTask->project->archived_at !== null) {
                throw ValidationException::withMessages([
                    'task' => 'لا يمكن تغيير أرشيف مهمة داخل مشروع مؤرشف.',
                ]);
            }

            $isArchived = $lockedTask->archived_at !== null;
            if ($archive === $isArchived) {
                throw ValidationException::withMessages([
                    'task' => $archive ? 'المهمة مؤرشفة بالفعل.' : 'المهمة نشطة بالفعل.',
                ]);
            }

            $before = $lockedTask->toArray();
            $lockedTask->archived_at = $archive ? Carbon::now() : null;
            $lockedTask->lock_version++;
            $lockedTask->save();
            $this->activityLogger->record(
                $lockedTask,
                $archive ? 'task.archived' : 'task.restored',
                $actor,
                $before,
                $lockedTask->toArray(),
                request(),
            );

            return $lockedTask->load(['project', 'status', 'assignee']);
        });
    }

    /** @param array<string, mixed> $data */
    private function normalizeAssignment(array &$data, ?Task $existing = null): void
    {
        $assigneeId = $data['assignee_id'] ?? null;

        if ($assigneeId === null) {
            $data['assigned_at'] = null;

            return;
        }

        if ($existing !== null && $existing->assignee_id === (int) $assigneeId && empty($data['assigned_at'])) {
            $data['assigned_at'] = $existing->assigned_at ?? Date::now();

            return;
        }

        $data['assigned_at'] ??= Date::now();
    }

    /** @param array<string, mixed> $data */
    private function normalizeCompletion(array &$data, ?Task $existing = null): void
    {
        $status = WorkflowStatus::query()->findOrFail((int) $data['status_id']);
        $isDone = $status->semantic === 'done';
        $data['completed_at'] = $isDone
            ? ($existing !== null ? ($existing->completed_at ?? Date::now()) : Date::now())
            : null;
    }
}
