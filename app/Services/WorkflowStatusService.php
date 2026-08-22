<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowStatusService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * @return list<array{id: int, entity_type: string, code: string, label: string, semantic: string, color: string, position: int, is_active: bool, usage_count: int}>
     */
    public function all(string $entityType): array
    {
        $this->ensureSupportedEntityType($entityType);

        return $this->statusData($entityType);
    }

    /**
     * @param  list<array{id: int, label: string, semantic: string, color: string, position: int, is_active: bool}>  $submitted
     * @return list<array{id: int, entity_type: string, code: string, label: string, semantic: string, color: string, position: int, is_active: bool, usage_count: int}>
     */
    public function update(
        string $entityType,
        array $submitted,
        User $actor,
        Request $request,
    ): array {
        $this->ensureSupportedEntityType($entityType);

        return DB::transaction(function () use ($entityType, $submitted, $actor, $request): array {
            /** @var Collection<int, WorkflowStatus> $statuses */
            $statuses = WorkflowStatus::query()
                ->where('entity_type', $entityType)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertCompleteSet($statuses, $submitted);

            /** @var Collection<int, array{id: int, label: string, semantic: string, color: string, position: int, is_active: bool}> $byId */
            $byId = collect($submitted)->keyBy('id');
            $errors = [];

            foreach ($statuses as $status) {
                $change = $byId->get($status->id);
                if ($change === null || ! $status->is_active || $change['is_active']) {
                    continue;
                }

                $usageCount = $this->usageCount($status, $entityType);
                if ($usageCount > 0) {
                    $index = $this->submittedIndex($submitted, $status->id);
                    $errors["statuses.{$index}.is_active"] = "لا يمكن تعطيل الحالة «{$status->label}» لأنها مستخدمة في {$usageCount} سجل/سجلات.";
                }
            }

            $hasActiveInitialStatus = $statuses->contains(function (WorkflowStatus $status) use ($byId): bool {
                $change = $byId->get($status->id);

                return $change !== null
                    && $change['semantic'] === WorkflowStatus::INITIAL_SEMANTIC
                    && $change['is_active'];
            });

            if (! $hasActiveInitialStatus) {
                $errors['statuses'] = 'يجب إبقاء حالة ابتدائية واحدة على الأقل مفعّلة.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($statuses as $status) {
                $change = $byId->get($status->id);
                if ($change === null) {
                    continue;
                }

                $before = $status->toArray();
                $status->fill([
                    'label' => trim($change['label']),
                    'semantic' => $change['semantic'],
                    'color' => strtoupper($change['color']),
                    'position' => $change['position'],
                    'is_active' => $change['is_active'],
                ]);

                if (! $status->isDirty()) {
                    continue;
                }

                $status->save();
                $this->activityLogger->record(
                    $status,
                    'workflow_status.updated',
                    $actor,
                    $before,
                    $status->fresh()->toArray(),
                    $request,
                );
            }

            return $this->statusData($entityType);
        });
    }

    /**
     * @param  Collection<int, WorkflowStatus>  $statuses
     * @param  list<array{id: int, label: string, semantic: string, color: string, position: int, is_active: bool}>  $submitted
     */
    private function assertCompleteSet(Collection $statuses, array $submitted): void
    {
        $expectedIds = $statuses->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $submittedIds = collect($submitted)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();

        if ($expectedIds !== $submittedIds) {
            throw ValidationException::withMessages([
                'statuses' => 'يجب إرسال جميع حالات هذا النوع مرة واحدة؛ لا تُحذف الحالات من المجموعة.',
            ]);
        }
    }

    private function usageCount(WorkflowStatus $status, string $entityType): int
    {
        return match ($entityType) {
            'project' => $status->projects()->count(),
            'task' => $status->tasks()->count(),
            'requirement' => $status->requirements()->count(),
            default => 0,
        };
    }

    /**
     * @param  list<array{id: int, label: string, semantic: string, color: string, position: int, is_active: bool}>  $submitted
     */
    private function submittedIndex(array $submitted, int $statusId): int
    {
        foreach ($submitted as $index => $status) {
            if ($status['id'] === $statusId) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @return list<array{id: int, entity_type: string, code: string, label: string, semantic: string, color: string, position: int, is_active: bool, usage_count: int}>
     */
    private function statusData(string $entityType): array
    {
        $relation = match ($entityType) {
            'project' => 'projects',
            'task' => 'tasks',
            'requirement' => 'requirements',
            default => throw new \InvalidArgumentException('Unsupported workflow entity type.'),
        };

        $statuses = WorkflowStatus::query()
            ->where('entity_type', $entityType)
            ->withCount([$relation.' as usage_count'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(static fn (WorkflowStatus $status): array => [
                'id' => $status->id,
                'entity_type' => $status->entity_type,
                'code' => $status->code,
                'label' => $status->label,
                'semantic' => $status->semantic,
                'color' => $status->color,
                'position' => $status->position,
                'is_active' => $status->is_active,
                'usage_count' => (int) $status->getAttribute('usage_count'),
            ])
            ->all();

        return array_values($statuses);
    }

    private function ensureSupportedEntityType(string $entityType): void
    {
        abort_unless(in_array($entityType, WorkflowStatus::ENTITY_TYPES, true), 404);
    }
}
