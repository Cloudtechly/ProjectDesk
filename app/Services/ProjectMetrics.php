<?php

namespace App\Services;

use App\Models\Project;
use App\Models\TimelineEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

class ProjectMetrics
{
    private const METRIC_ATTRIBUTES = [
        'metric_total_tasks',
        'metric_done_tasks',
        'metric_open_tasks',
        'metric_overdue_tasks',
        'metric_requirements',
        'metric_high_risks',
        'metric_next_stage_id',
        'metric_next_stage_title',
        'metric_next_stage_kind',
        'metric_next_stage_status',
        'metric_next_stage_starts_at',
    ];

    /**
     * Add every project metric as correlated aggregates so lists do not issue
     * one query per project.
     *
     * Formula contract:
     * - progress = DONE / all non-archived, non-CANCELLED tasks;
     * - danger = an overdue open task or an open risk with score >= 16;
     * - attention = open work without a danger signal; otherwise healthy;
     * - next stage = the current in-progress or earliest future, non-meeting
     *   timeline entry that is neither completed, cancelled, nor archived.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function withMetrics(Builder $query): Builder
    {
        $now = Date::now();

        return $query
            ->withCount($this->countDefinitions($now))
            ->addSelect([
                'metric_next_stage_id' => $this->nextStageSelect('id', $now),
                'metric_next_stage_title' => $this->nextStageSelect('title', $now),
                'metric_next_stage_kind' => $this->nextStageSelect('kind', $now),
                'metric_next_stage_status' => $this->nextStageSelect('status', $now),
                'metric_next_stage_starts_at' => $this->nextStageSelect('starts_at', $now),
            ]);
    }

    /**
     * Backward-compatible name for callers that only need aggregate counts.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function withCounts(Builder $query): Builder
    {
        return $this->withMetrics($query);
    }

    /** @return array{progress: int, health: string, total_tasks: int, open_tasks: int, overdue_tasks: int, requirements: int, high_risks: int, next_stage: array{id: int, title: string, kind: string, status: string, starts_at: string}|null} */
    public function for(Project $project): array
    {
        if (! $project->hasAttribute('metric_total_tasks') || ! $project->hasAttribute('metric_next_stage_id')) {
            $snapshot = $this->withMetrics(Project::query()->whereKey($project->getKey()))->firstOrFail();

            foreach (self::METRIC_ATTRIBUTES as $attribute) {
                $project->setAttribute($attribute, $snapshot->getAttribute($attribute));
            }
        }

        return $this->fromCounts($project);
    }

    /** @return array{progress: int, health: string, total_tasks: int, open_tasks: int, overdue_tasks: int, requirements: int, high_risks: int, next_stage: array{id: int, title: string, kind: string, status: string, starts_at: string}|null} */
    public function fromCounts(Project $project): array
    {
        $total = (int) $project->getAttribute('metric_total_tasks');
        $done = (int) $project->getAttribute('metric_done_tasks');
        $open = (int) $project->getAttribute('metric_open_tasks');
        $overdue = (int) $project->getAttribute('metric_overdue_tasks');
        $requirements = (int) $project->getAttribute('metric_requirements');
        $highRisks = (int) $project->getAttribute('metric_high_risks');
        $nextStageId = $project->getAttribute('metric_next_stage_id');

        $health = $overdue > 0 || $highRisks > 0 ? 'danger' : ($open > 0 ? 'attention' : 'healthy');
        $nextStage = $nextStageId === null ? null : [
            'id' => (int) $nextStageId,
            'title' => (string) $project->getAttribute('metric_next_stage_title'),
            'kind' => (string) $project->getAttribute('metric_next_stage_kind'),
            'status' => (string) $project->getAttribute('metric_next_stage_status'),
            'starts_at' => CarbonImmutable::parse(
                (string) $project->getAttribute('metric_next_stage_starts_at'),
            )->toIso8601String(),
        ];

        return [
            'progress' => $total === 0 ? 0 : (int) round(($done / $total) * 100),
            'health' => $health,
            'total_tasks' => $total,
            'open_tasks' => $open,
            'overdue_tasks' => $overdue,
            'requirements' => $requirements,
            'high_risks' => $highRisks,
            'next_stage' => $nextStage,
        ];
    }

    /**
     * Apply the exact health formula used by {@see fromCounts()} to a project query.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function whereHealth(Builder $query, string $health, ?CarbonInterface $at = null): Builder
    {
        $now = $at ?? Date::now();

        return match ($health) {
            'danger' => $query->where(fn (Builder $projects) => $projects
                ->whereHas('tasks', fn (Builder $tasks) => $this->overdueTasks($tasks, $now))
                ->orWhereHas('risks', fn (Builder $risks) => $this->highRisks($risks))),
            'attention' => $query
                ->whereDoesntHave('tasks', fn (Builder $tasks) => $this->overdueTasks($tasks, $now))
                ->whereDoesntHave('risks', fn (Builder $risks) => $this->highRisks($risks))
                ->whereHas('tasks', fn (Builder $tasks) => $this->openTasks($tasks)),
            'healthy' => $query
                ->whereDoesntHave('tasks', fn (Builder $tasks) => $this->overdueTasks($tasks, $now))
                ->whereDoesntHave('risks', fn (Builder $risks) => $this->highRisks($risks))
                ->whereDoesntHave('tasks', fn (Builder $tasks) => $this->openTasks($tasks)),
            default => $query,
        };
    }

    /** @return array<string, callable> */
    private function countDefinitions(CarbonInterface $now): array
    {
        return [
            'tasks as metric_total_tasks' => fn (Builder $query) => $query
                ->whereNull('archived_at')
                ->whereHas('status', fn (Builder $status) => $status->where('semantic', '!=', 'cancelled')),
            'tasks as metric_done_tasks' => fn ($query) => $query
                ->whereNull('archived_at')
                ->whereHas('status', fn ($status) => $status->where('semantic', 'done')),
            'tasks as metric_open_tasks' => fn (Builder $query) => $this->openTasks($query),
            'tasks as metric_overdue_tasks' => fn (Builder $query) => $this->overdueTasks($query, $now),
            'requirements as metric_requirements' => fn ($query) => $query->whereNull('archived_at'),
            'risks as metric_high_risks' => fn (Builder $query) => $this->highRisks($query),
        ];
    }

    /** @return Builder<TimelineEntry> */
    private function nextStageSelect(string $column, CarbonInterface $now): Builder
    {
        return TimelineEntry::query()
            ->select('timeline_entries.'.$column)
            ->whereColumn('timeline_entries.project_id', 'projects.id')
            ->whereNull('timeline_entries.archived_at')
            ->where('timeline_entries.kind', '!=', 'meeting')
            ->where(function (Builder $timeline) use ($now): void {
                $timeline->where('timeline_entries.status', 'in_progress')
                    ->orWhere(function (Builder $future) use ($now): void {
                        $future->where('timeline_entries.status', 'planned')
                            ->where('timeline_entries.starts_at', '>=', $now);
                    });
            })
            ->orderByRaw("CASE WHEN timeline_entries.status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderBy('timeline_entries.starts_at')
            ->orderBy('timeline_entries.id')
            ->limit(1);
    }

    /** @param Builder<Model> $query */
    private function openTasks(Builder $query): void
    {
        $query
            ->whereNull('archived_at')
            ->whereHas('status', fn (Builder $status) => $status->whereNotIn('semantic', ['done', 'cancelled']));
    }

    /** @param Builder<Model> $query */
    private function overdueTasks(Builder $query, CarbonInterface $now): void
    {
        $this->openTasks($query);
        $query->where('due_at', '<', $now);
    }

    /** @param Builder<Model> $query */
    private function highRisks(Builder $query): void
    {
        $query
            ->whereNull('archived_at')
            ->where('status', 'open')
            ->whereRaw('(probability * impact) >= ?', [16]);
    }
}
