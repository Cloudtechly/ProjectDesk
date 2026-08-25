<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Http\Request;

class ProjectIndexData
{
    public function __construct(private readonly ProjectMetrics $metrics) {}

    /** @return array<string, mixed> */
    public function for(Request $request, User $user): array
    {
        $sortColumns = [
            'end_date' => 'end_date',
            'start_date' => 'start_date',
            'name' => 'name',
            'priority' => 'priority',
            'created_at' => 'created_at',
        ];
        $sort = $request->string('sort')->toString();
        $sort = array_key_exists($sort, $sortColumns) ? $sort : 'end_date';
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';
        $scope = $request->string('scope')->toString() === 'archived' ? 'archived' : 'active';

        $projectsQuery = Project::query()
            ->visibleTo($user)
            ->when(
                $scope === 'archived',
                fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at'),
            )
            ->with(['client:id,name', 'status:id,label,color,semantic', 'manager:id,name'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = '%'.$request->string('q')->toString().'%';
                $query->where(fn ($project) => $project->where('name', 'like', $search)->orWhere('code', 'like', $search));
            })
            ->when(
                $request->string('activity')->toString() === 'active',
                fn ($query) => $query->whereHas(
                    'status',
                    fn ($status) => $status->whereNotIn('semantic', ['done', 'cancelled']),
                ),
            )
            ->when(
                $request->string('risk')->toString() === 'high',
                fn ($query) => $query->whereHas(
                    'risks',
                    fn ($risks) => $risks
                        ->whereNull('archived_at')
                        ->where('status', 'open')
                        ->whereRaw('(probability * impact) >= ?', [16]),
                ),
            )
            ->when(
                in_array($request->string('health')->toString(), ['danger', 'attention', 'healthy'], true),
                fn ($query) => $this->metrics->whereHealth($query, $request->string('health')->toString()),
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status_id', $request->integer('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')->toString()))
            ->when($request->filled('client'), fn ($query) => $query->where('client_id', $request->integer('client')))
            ->when(
                $sort === 'priority',
                fn ($query) => $query->orderByRaw("CASE priority WHEN 'low' THEN 1 WHEN 'medium' THEN 2 WHEN 'high' THEN 3 WHEN 'critical' THEN 4 ELSE 5 END {$direction}"),
                fn ($query) => $query->orderBy($sortColumns[$sort], $direction),
            );

        $projects = $this->metrics->withMetrics($projectsQuery)
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Project $project) use ($user): array {
                $projectMetrics = $this->metrics->fromCounts($project);

                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'client' => $project->client?->name,
                    'status' => $project->status->label,
                    'statusColor' => $project->status->color,
                    'manager' => $project->manager?->name,
                    'priority' => $project->priority,
                    'progress' => $projectMetrics['progress'],
                    'health' => $projectMetrics['health'],
                    'openTasks' => $projectMetrics['open_tasks'],
                    'overdueTasks' => $projectMetrics['overdue_tasks'],
                    'nextStage' => $projectMetrics['next_stage'],
                    'currentPhase' => $projectMetrics['current_phase'],
                    'nextMilestone' => $projectMetrics['next_milestone'],
                    'progressMode' => $projectMetrics['progress_mode'],
                    'startDate' => $project->start_date?->toDateString(),
                    'endDate' => $project->end_date?->toDateString(),
                    'archivedAt' => $project->archived_at?->toIso8601String(),
                    'lockVersion' => $project->lock_version,
                    'canRestore' => $user->can('restore', $project),
                ];
            });

        return [
            'projects' => $projects,
            'filters' => [
                ...$request->only(['q', 'status', 'priority', 'client', 'activity', 'risk', 'health']),
                'sort' => $sort,
                'direction' => $direction,
                'scope' => $scope,
            ],
            'statuses' => WorkflowStatus::query()->where('entity_type', 'project')->where('is_active', true)->orderBy('position')->get(),
            'taskStatuses' => WorkflowStatus::query()->where('entity_type', 'task')->where('is_active', true)->orderBy('position')->get(['id', 'label']),
            'clients' => Client::query()
                ->when(
                    $user->can('create', Project::class),
                    fn ($query) => $query->manageableBy($user),
                    fn ($query) => $query->visibleTo($user),
                )
                ->whereNull('archived_at')
                ->where('status', 'active')
                ->when(
                    $user->can('create', Project::class),
                    fn ($query) => $query->with(['contacts' => fn ($contacts) => $contacts->where('is_active', true)->orderByDesc('is_primary')->orderBy('name')]),
                )
                ->orderBy('name')
                ->get(['id', 'name']),
            'members' => $user->can('create', Project::class)
                ? User::query()->where('status', 'active')->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'global_role'])
                : [],
            'canCreate' => $user->can('create', Project::class),
        ];
    }
}
