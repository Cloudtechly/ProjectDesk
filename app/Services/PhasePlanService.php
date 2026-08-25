<?php

namespace App\Services;

use App\Models\Project;
use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhasePlanService
{
    /**
     * @param  list<array<string, mixed>>  $phases
     * @return array<string, mixed>
     */
    public function replace(Project $project, array $phases, User $actor): array
    {
        $active = collect($phases)->reject(fn (array $phase): bool => ($phase['status'] ?? null) === 'cancelled');
        $weight = round((float) $active->sum(fn (array $phase): float => (float) ($phase['weight_percent'] ?? 0)), 2);
        if ($active->isEmpty() || abs($weight - 100.0) > 0.001) {
            throw ValidationException::withMessages([
                'phases' => 'يجب أن يساوي مجموع أوزان المراحل غير الملغاة 100%.',
            ]);
        }

        return DB::transaction(function () use ($project, $phases, $actor): array {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = $lockedProject->phases()->whereNull('archived_at')->lockForUpdate()->get()->keyBy('id');
            $keptIds = [];

            foreach ($phases as $phaseData) {
                $phase = $this->savePhase($lockedProject, $existing, $phaseData, $actor);
                $keptIds[] = $phase->id;
                $this->replaceMilestones($lockedProject, $phase, (array) ($phaseData['milestones'] ?? []), $actor);
            }

            $removed = $existing->except($keptIds);
            foreach ($removed as $phase) {
                $phase->update(['archived_at' => now(), 'lock_version' => $phase->lock_version + 1]);
                $phase->milestones()->whereNull('archived_at')->update(['archived_at' => now()]);
            }

            $lockedProject->update([
                'progress_mode' => 'phases',
                'lock_version' => $lockedProject->lock_version + 1,
            ]);

            return $this->summary($lockedProject->fresh());
        });
    }

    /** @return array<string, mixed> */
    public function summary(Project $project): array
    {
        $phases = $project->relationLoaded('phases')
            ? $project->phases->whereNull('archived_at')->sortBy('starts_at')->values()
            : $project->phases()->whereNull('archived_at')->with([
                'tasks' => fn ($query) => $query->whereNull('archived_at')->with('status:id,semantic'),
                'milestones' => fn ($query) => $query->whereNull('archived_at')->orderBy('starts_at'),
            ])->orderBy('starts_at')->get();

        $rows = $phases->map(fn (TimelineEntry $phase): array => $this->phaseSummary($phase))->values();
        $weighted = (float) $rows->sum(
            fn (array $phase): float => $phase['status'] === 'cancelled'
                ? 0
                : ((float) $phase['weight_percent'] * (int) $phase['progress']) / 100,
        );
        $now = now();
        $current = $rows->first(fn (array $phase): bool => $phase['status'] === 'in_progress')
            ?? $rows->first(fn (array $phase): bool => $phase['starts_at'] <= $now->toIso8601String()
                && ($phase['ends_at'] === null || $phase['ends_at'] >= $now->toIso8601String())
                && ! in_array($phase['status'], ['completed', 'cancelled'], true));
        $nextMilestone = $rows->flatMap(fn (array $phase): array => $phase['milestones'])
            ->filter(fn (array $milestone): bool => ! in_array($milestone['status'], ['completed', 'cancelled'], true))
            ->sortBy('starts_at')->first();

        return [
            'progress' => (int) round($weighted),
            'health' => $this->projectHealth($rows),
            'current_phase' => $current,
            'next_milestone' => $nextMilestone,
            'weight_total' => round((float) $rows->where('status', '!=', 'cancelled')->sum('weight_percent'), 2),
            'phases' => $rows->all(),
        ];
    }

    /**
     * @param  Collection<int, TimelineEntry>  $existing
     * @param  array<string, mixed>  $data
     */
    private function savePhase(Project $project, Collection $existing, array $data, User $actor): TimelineEntry
    {
        $id = isset($data['id']) ? (int) $data['id'] : null;
        $phase = $id === null ? new TimelineEntry(['project_id' => $project->id, 'kind' => 'phase']) : $existing->get($id);
        if (! $phase instanceof TimelineEntry) {
            throw ValidationException::withMessages(['phases' => 'إحدى المراحل لا تنتمي إلى هذا المشروع.']);
        }

        $status = (string) $data['status'];
        $phase->fill([
            'title' => $data['title'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
            'status' => $status, 'weight_percent' => $data['weight_percent'],
            'completion_criteria' => $data['completion_criteria'] ?? null,
            'owner_id' => $data['owner_id'] ?? null, 'note' => $data['note'] ?? null,
            'completed_at' => $status === 'completed' ? ($phase->completed_at ?? now()) : null,
            'completed_by' => $status === 'completed' ? ($phase->completed_by ?? $actor->id) : null,
            'lock_version' => $phase->exists ? $phase->lock_version + 1 : 1,
        ]);
        $phase->save();

        return $phase;
    }

    /** @param array<array-key, mixed> $milestones */
    private function replaceMilestones(Project $project, TimelineEntry $phase, array $milestones, User $actor): void
    {
        $existing = $phase->milestones()->whereNull('archived_at')->lockForUpdate()->get()->keyBy('id');
        $kept = [];
        foreach ($milestones as $data) {
            if (! is_array($data)) {
                throw ValidationException::withMessages(['phases' => 'صيغة بيانات أحد المعالم غير صحيحة.']);
            }
            $id = isset($data['id']) ? (int) $data['id'] : null;
            $milestone = $id === null
                ? new TimelineEntry(['project_id' => $project->id, 'parent_phase_id' => $phase->id, 'kind' => 'milestone'])
                : $existing->get($id);
            if (! $milestone instanceof TimelineEntry) {
                throw ValidationException::withMessages(['phases' => 'أحد المعالم لا ينتمي إلى مرحلته.']);
            }
            $status = (string) $data['status'];
            $milestone->fill([
                'title' => $data['title'], 'starts_at' => $data['date'], 'ends_at' => null,
                'status' => $status, 'is_gate' => (bool) ($data['is_gate'] ?? false),
                'completion_criteria' => $data['completion_criteria'] ?? null,
                'owner_id' => $data['owner_id'] ?? null, 'note' => $data['note'] ?? null,
                'completed_at' => $status === 'completed' ? ($milestone->completed_at ?? now()) : null,
                'completed_by' => $status === 'completed' ? ($milestone->completed_by ?? $actor->id) : null,
                'lock_version' => $milestone->exists ? $milestone->lock_version + 1 : 1,
            ]);
            $milestone->save();
            $kept[] = $milestone->id;
        }
        $existing->except($kept)->each(fn (TimelineEntry $entry) => $entry->update([
            'archived_at' => now(), 'lock_version' => $entry->lock_version + 1,
        ]));

        if ($phase->status === 'completed' && $phase->milestones()->whereNull('archived_at')
            ->where('is_gate', true)->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            throw ValidationException::withMessages([
                'phases' => 'لا يمكن إكمال مرحلة قبل اعتماد جميع معالمها الإلزامية.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function phaseSummary(TimelineEntry $phase): array
    {
        $eligibleTasks = $phase->tasks->reject(fn ($task): bool => $task->status->semantic === 'cancelled');
        $doneTasks = $eligibleTasks->filter(fn ($task): bool => $task->status->semantic === 'done')->count();
        $openGate = $phase->milestones->first(fn (TimelineEntry $entry): bool => $entry->is_gate
            && ! in_array($entry->status, ['completed', 'cancelled'], true));
        $progress = $phase->status === 'completed' ? 100
            : ($eligibleTasks->isEmpty() ? 0 : (int) round(($doneTasks / $eligibleTasks->count()) * 100));
        if ($progress >= 100 && $openGate instanceof TimelineEntry) {
            $progress = 99;
        }

        $milestones = $phase->milestones->map(fn (TimelineEntry $entry): array => [
            'id' => $entry->id, 'title' => $entry->title, 'starts_at' => $entry->starts_at->toIso8601String(),
            'status' => $entry->status, 'is_gate' => $entry->is_gate,
            'is_overdue' => ! in_array($entry->status, ['completed', 'cancelled'], true) && $entry->starts_at->isPast(),
        ])->values()->all();

        return [
            'id' => $phase->id, 'title' => $phase->title, 'status' => $phase->status,
            'weight_percent' => (float) $phase->weight_percent, 'progress' => $progress,
            'awaiting_approval' => $progress === 99 && $openGate instanceof TimelineEntry,
            'starts_at' => $phase->starts_at->toIso8601String(), 'ends_at' => $phase->ends_at?->toIso8601String(),
            'health' => $this->phaseHealth($phase, $openGate), 'milestones' => $milestones,
        ];
    }

    private function phaseHealth(TimelineEntry $phase, ?TimelineEntry $openGate): string
    {
        if ($phase->status === 'completed') {
            return 'completed';
        }
        if ($phase->ends_at?->isPast() || $openGate?->starts_at->isPast()) {
            return 'overdue';
        }
        if (($phase->ends_at !== null && $phase->ends_at->lte(now()->addDays(14)))
            || ($openGate !== null && $openGate->starts_at->lte(now()->addDays(14)))) {
            return 'attention';
        }

        return 'on_track';
    }

    /** @param Collection<int, array<string, mixed>> $phases */
    private function projectHealth(Collection $phases): string
    {
        if ($phases->isNotEmpty() && $phases->every(fn (array $phase): bool => in_array($phase['status'], ['completed', 'cancelled'], true))) {
            return 'completed';
        }
        if ($phases->contains('health', 'overdue')) {
            return 'overdue';
        }
        if ($phases->contains('health', 'attention')) {
            return 'attention';
        }

        return 'on_track';
    }
}
