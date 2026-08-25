<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectMetrics;
use App\Services\WeeklyScheduleBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ProjectMetrics $metrics, WeeklyScheduleBuilder $schedule): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'week' => ['nullable', 'date_format:Y-m-d'],
            'direction' => ['nullable', Rule::in(['previous', 'next'])],
        ]);

        $projectQuery = Project::query()->visibleTo($user)->whereNull('archived_at');
        $projectIds = (clone $projectQuery)->pluck('id');
        $activeProjectQuery = (clone $projectQuery)
            ->whereHas('status', fn ($query) => $query->whereNotIn('semantic', ['done', 'cancelled']));
        $manageableProjectIds = $user->global_role === 'admin'
            ? $projectIds->map(fn ($id): int => (int) $id)->all()
            : Project::query()
                ->visibleTo($user)
                ->whereNull('archived_at')
                ->where(fn ($projects) => $projects
                    ->where('manager_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members
                        ->whereKey($user->id)
                        ->where('project_members.project_role', 'manager')
                        ->where('project_members.status', 'active')))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        $visibleTasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('tasks.archived_at');
        $activeTasks = (clone $visibleTasks)
            ->whereHas('status', fn ($query) => $query->whereNotIn('semantic', ['done', 'cancelled']));

        $now = CarbonImmutable::instance(Date::now());
        $followUpEnd = $now->addDays(7);
        $overdueTasks = (clone $activeTasks)->where('due_at', '<', $now)->count();
        $dueSoonTasks = (clone $activeTasks)->whereBetween('due_at', [$now, $followUpEnd])->count();
        $highRiskProjects = (clone $projectQuery)
            ->whereHas('risks', fn ($risks) => $risks
                ->whereNull('archived_at')
                ->where('status', 'open')
                ->whereRaw('(probability * impact) >= ?', [16]))
            ->count();
        $activeProjects = (clone $activeProjectQuery)->count();

        $dashboardProjectsQuery = (clone $activeProjectQuery)
            ->with(['client:id,name', 'status:id,label,color,semantic'])
            ->orderBy('end_date')
            ->limit(8);
        $projects = $metrics->withMetrics($dashboardProjectsQuery)
            ->get()
            ->map(function (Project $project) use ($metrics): array {
                $projectMetrics = $metrics->fromCounts($project);

                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'client' => $project->client?->name,
                    'status' => $project->status->label,
                    'statusColor' => $project->status->color,
                    'priority' => $project->priority,
                    'progress' => $projectMetrics['progress'],
                    'health' => $projectMetrics['health'],
                    'nextStage' => $projectMetrics['next_stage'],
                    'currentPhase' => $projectMetrics['current_phase'],
                    'nextMilestone' => $projectMetrics['next_milestone'],
                    'startDate' => $project->start_date?->toDateString(),
                    'endDate' => $project->end_date?->toDateString(),
                ];
            });

        $attentionTasks = (clone $activeTasks)
            ->where('due_at', '<=', $followUpEnd)
            ->with(['project:id,name', 'assignee:id,name', 'status:id,label,semantic'])
            ->orderBy('due_at')
            ->limit(8)
            ->get()
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'code' => $task->code,
                'title' => $task->title,
                'project' => $task->project->name,
                'assignee' => $task->assignee?->name,
                'status' => $task->status->label,
                'dueAt' => $task->due_at->toIso8601String(),
                'isOverdue' => $task->due_at->isPast(),
                'href' => in_array($task->project_id, $manageableProjectIds, true)
                    ? route('tasks.edit', $task, false)
                    : route('tasks.index', ['project' => $task->project_id, 'q' => $task->code], false),
            ]);

        $risks = Risk::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('archived_at')
            ->where('status', 'open')
            ->whereRaw('(probability * impact) >= ?', [16])
            ->with('project:id,name')
            ->orderByRaw('(probability * impact) DESC')
            ->limit(6)
            ->get()
            ->map(fn (Risk $risk): array => [
                'id' => $risk->id,
                'title' => $risk->title,
                'projectId' => $risk->project_id,
                'project' => $risk->project->name,
                'score' => $risk->probability * $risk->impact,
                'status' => $risk->status,
                'href' => route('projects.show', ['project' => $risk->project_id, 'tab' => 'risks'], false),
            ]);

        $issues = Issue::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('archived_at')
            ->whereIn('status', ['open', 'in_progress'])
            ->whereIn('severity', ['high', 'critical'])
            ->with('project:id,name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
            ->orderBy('due_at')
            ->limit(6)
            ->get()
            ->map(fn (Issue $issue): array => [
                'id' => $issue->id,
                'title' => $issue->title,
                'projectId' => $issue->project_id,
                'project' => $issue->project->name,
                'severity' => $issue->severity,
                'status' => $issue->status,
                'dueAt' => $issue->due_at?->toIso8601String(),
                'href' => route('projects.show', ['project' => $issue->project_id, 'tab' => 'issues'], false),
            ]);

        $workloadRows = (clone $activeTasks)
            ->getQuery()
            ->leftJoin('users', 'users.id', '=', 'tasks.assignee_id')
            ->whereNull('tasks.archived_at')
            ->selectRaw('tasks.assignee_id, users.name, COUNT(*) as open_count')
            ->selectRaw('SUM(CASE WHEN tasks.due_at < ? THEN 1 ELSE 0 END) as overdue_count', [$now])
            ->groupBy('tasks.assignee_id', 'users.name')
            ->orderByDesc('open_count')
            ->limit(12)
            ->get();
        $workload = $workloadRows
            ->map(fn ($row): array => [
                'id' => $row->assignee_id === null ? 'unassigned' : (string) $row->assignee_id,
                'name' => $row->assignee_id === null ? 'غير مسند' : (string) $row->name,
                'open' => (int) $row->open_count,
                'overdue' => (int) $row->overdue_count,
                'href' => route('tasks.index', [
                    'assignee' => $row->assignee_id === null ? 'unassigned' : $row->assignee_id,
                ], false),
            ]);

        $taskStatusDistribution = (clone $visibleTasks)
            ->getQuery()
            ->join('workflow_statuses', 'workflow_statuses.id', '=', 'tasks.status_id')
            ->selectRaw('workflow_statuses.id, workflow_statuses.label, workflow_statuses.color, workflow_statuses.semantic, workflow_statuses.position, COUNT(*) as aggregate_count')
            ->groupBy(
                'workflow_statuses.id',
                'workflow_statuses.label',
                'workflow_statuses.color',
                'workflow_statuses.semantic',
                'workflow_statuses.position',
            )
            ->orderBy('workflow_statuses.position')
            ->orderBy('workflow_statuses.id')
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'label' => (string) $row->label,
                'color' => (string) $row->color,
                'semantic' => (string) $row->semantic,
                'count' => (int) $row->aggregate_count,
                'href' => route('tasks.index', ['status' => $row->id], false),
            ]);

        $healthLabels = [
            'danger' => 'تحتاج تدخلاً',
            'attention' => 'تحتاج متابعة',
            'healthy' => 'مستقرة',
        ];
        $projectHealthDistribution = collect($healthLabels)
            ->map(fn (string $label, string $health): array => [
                'key' => $health,
                'label' => $label,
                'count' => $metrics->whereHealth(clone $activeProjectQuery, $health, $now)->count(),
                'href' => route('projects.index', ['activity' => 'active', 'health' => $health], false),
            ])
            ->values();

        $weekAnchor = (string) ($validated['week'] ?? Date::now()->toDateString());
        $direction = (string) ($validated['direction'] ?? '');
        if ($direction !== '') {
            $weekAnchor = CarbonImmutable::parse($weekAnchor, config('app.timezone'))
                ->addWeeks($direction === 'next' ? 1 : -1)
                ->toDateString();
        }
        $weeklySchedule = $schedule->build($user, $weekAnchor);

        return Inertia::render('dashboard', [
            'summary' => [
                'activeProjects' => $activeProjects,
                'overdueTasks' => $overdueTasks,
                'dueSoonTasks' => $dueSoonTasks,
                'highRisks' => $highRiskProjects,
            ],
            'projects' => $projects,
            'tasks' => $attentionTasks,
            'risks' => $risks,
            'issues' => $issues,
            'workload' => $workload,
            'taskStatusDistribution' => $taskStatusDistribution,
            'projectHealthDistribution' => $projectHealthDistribution,
            'weeklySchedule' => $weeklySchedule,
            'currentWeek' => $weeklySchedule['weekStart'],
            'selectedDate' => $weekAnchor,
        ]);
    }
}
