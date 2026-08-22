<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTaskRequest;
use App\Http\Requests\UpdateTaskStatusRequest;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowStatus;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $projectIds = Project::query()->visibleTo($user)->whereNull('archived_at')->pluck('id');
        $projectMembers = $this->projectMembers($user);
        $manageableProjectIds = $this->manageableProjectIds($user);
        $archivedMode = $request->boolean('archived');
        $sortColumns = [
            'due_at' => 'due_at',
            'start_at' => 'start_at',
            'title' => 'title',
            'priority' => 'priority',
            'created_at' => 'created_at',
            'assigned_at' => 'assigned_at',
        ];
        $requestedSort = $request->string('sort')->toString();
        $sort = array_key_exists($requestedSort, $sortColumns) ? $requestedSort : 'due_at';
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        $taskQuery = Task::query()
            ->whereIn('project_id', $projectIds)
            ->when(
                $archivedMode,
                fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at'),
            )
            ->with([
                'project:id,code,name', 'status:id,label,color,semantic', 'assignee:id,name',
                'requirements:id,project_id,code,title',
            ])
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = '%'.$request->string('q')->toString().'%';
                $query->where(fn ($task) => $task->where('title', 'like', $search)->orWhere('code', 'like', $search));
            })
            ->when($request->filled('project'), fn ($query) => $query->where('project_id', $request->integer('project')))
            ->when($request->filled('assignee'), fn ($query) => $request->string('assignee')->toString() === 'unassigned'
                ? $query->whereNull('assignee_id')
                : $query->where('assignee_id', $request->integer('assignee')))
            ->when($request->filled('status'), fn ($query) => $query->where('status_id', $request->integer('status')))
            ->when($request->string('due')->toString() === 'overdue', fn ($query) => $query
                ->where('due_at', '<', now())
                ->whereHas('status', fn ($status) => $status->whereNotIn('semantic', ['done', 'cancelled'])))
            ->when($request->string('due')->toString() === 'soon', fn ($query) => $query
                ->whereBetween('due_at', [now(), now()->addDays(7)])
                ->whereHas('status', fn ($status) => $status->whereNotIn('semantic', ['done', 'cancelled'])));

        if ($sort === 'priority') {
            $taskQuery->orderByRaw(
                "CASE priority WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END {$direction}",
            );
        } else {
            $taskQuery->orderBy($sortColumns[$sort], $direction);
        }

        $tasks = $taskQuery
            ->orderBy('id', $direction)
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Task $task): Task => $this->withCapabilities($task, $user, $manageableProjectIds));

        $visibleProjects = Project::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);
        $createProjects = $visibleProjects
            ->filter(fn (Project $project): bool => Gate::allows('create', [Task::class, $project]))
            ->values();

        return Inertia::render('tasks/index', [
            'tasks' => $tasks,
            'filters' => [
                ...$request->only(['q', 'project', 'assignee', 'status', 'due', 'view', 'archived']),
                'sort' => $sort,
                'direction' => $direction,
            ],
            'projects' => $visibleProjects,
            'createProjects' => $createProjects,
            'members' => collect($projectMembers)->flatten(1)->unique('id')->sortBy('name')->values(),
            'projectMembers' => $projectMembers,
            'projectRequirements' => $this->projectRequirements($user),
            'statuses' => WorkflowStatus::query()->where('entity_type', 'task')->where('is_active', true)->orderBy('position')->get(),
            'openCreate' => false,
            'canCreate' => $createProjects->isNotEmpty(),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $projects = Project::query()->visibleTo($user)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']);
        $createProjects = $projects
            ->filter(fn (Project $project): bool => Gate::allows('create', [Task::class, $project]))
            ->values();
        abort_if($createProjects->isEmpty(), 403);
        $projectMembers = $this->projectMembers($user);
        $selectedProject = $request->filled('project') ? Project::query()->findOrFail($request->integer('project')) : null;
        if ($selectedProject) {
            Gate::authorize('create', [Task::class, $selectedProject]);
        }

        return Inertia::render('tasks/index', [
            'tasks' => ['data' => [], 'links' => [], 'meta' => []],
            'filters' => [],
            'projects' => $projects,
            'createProjects' => $createProjects,
            'members' => collect($projectMembers)->flatten(1)->unique('id')->sortBy('name')->values(),
            'projectMembers' => $projectMembers,
            'projectRequirements' => $this->projectRequirements($user),
            'statuses' => WorkflowStatus::query()->where('entity_type', 'task')->where('is_active', true)->orderBy('position')->get(),
            'openCreate' => true,
            'canCreate' => true,
            'selectedProjectId' => $selectedProject?->id,
        ]);
    }

    public function edit(Request $request, Task $task): Response
    {
        $this->authorize('update', $task);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $projectIds = Project::query()->visibleTo($user)->whereNull('archived_at')->pluck('id');
        $projectMembers = $this->projectMembers($user);
        $manageableProjectIds = $this->manageableProjectIds($user);

        $task->load([
            'project:id,name', 'status:id,label,color,semantic', 'assignee:id,name',
            'requirements:id,project_id,code,title',
            'assignmentEvents.fromUser:id,name', 'assignmentEvents.toUser:id,name', 'assignmentEvents.recordedBy:id,name',
        ]);

        return Inertia::render('tasks/index', [
            'tasks' => Task::query()
                ->whereIn('project_id', $projectIds)
                ->whereNull('archived_at')
                ->with([
                    'project:id,code,name', 'status:id,label,color,semantic', 'assignee:id,name',
                    'requirements:id,project_id,code,title',
                ])
                ->orderBy('due_at')
                ->paginate(30)
                ->through(fn (Task $listedTask): Task => $this->withCapabilities($listedTask, $user, $manageableProjectIds)),
            'filters' => [],
            'projects' => Project::query()->visibleTo($user)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'createProjects' => Project::query()->visibleTo($user)->whereNull('archived_at')->get(['id', 'name'])
                ->filter(fn (Project $project): bool => Gate::allows('create', [Task::class, $project]))
                ->values(),
            'members' => $projectMembers[$task->project_id] ?? [],
            'projectMembers' => $projectMembers,
            'projectRequirements' => $this->projectRequirements($user),
            'statuses' => WorkflowStatus::query()->where('entity_type', 'task')->where('is_active', true)->orderBy('position')->get(),
            'openCreate' => false,
            'canCreate' => Project::query()->visibleTo($user)->whereNull('archived_at')->get()
                ->contains(fn (Project $project): bool => Gate::allows('create', [Task::class, $project])),
            'editingTask' => $task,
        ]);
    }

    public function store(SaveTaskRequest $request, TaskService $taskService): RedirectResponse
    {
        $project = Project::query()->findOrFail($request->integer('project_id'));
        Gate::authorize('create', [Task::class, $project]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $task = $taskService->create($request->validated(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "تمت إضافة المهمة {$task->code}."]);

        return to_route('tasks.index');
    }

    public function update(SaveTaskRequest $request, Task $task, TaskService $taskService): RedirectResponse
    {
        $this->authorize('update', $task);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $taskService->update($task, $request->validated(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث المهمة وسجل الإسناد.']);

        return back();
    }

    public function archive(Request $request, Task $task, TaskService $taskService): RedirectResponse
    {
        $this->authorize('delete', $task);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $taskService->archive($task, (int) $validated['lock_version'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة المهمة.']);

        return back();
    }

    public function restore(Request $request, Task $task, TaskService $taskService): RedirectResponse
    {
        $this->authorize('restore', $task);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $taskService->restore($task, (int) $validated['lock_version'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة المهمة.']);

        return back();
    }

    public function updateStatus(UpdateTaskStatusRequest $request, Task $task, TaskService $taskService): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $taskService->updateStatus($task, $request->integer('status_id'), $request->integer('lock_version'), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث حالة المهمة.']);

        return back();
    }

    /** @return array<int, array<int, array{id: int, name: string}>> */
    private function projectMembers(User $user): array
    {
        return Project::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->with(['members' => fn ($members) => $members
                ->where('users.status', 'active')
                ->whereNull('users.archived_at')
                ->where('project_members.status', 'active')
                ->whereIn('project_members.project_role', ['manager', 'member'])
                ->orderBy('users.name')])
            ->get(['id'])
            ->mapWithKeys(fn (Project $project): array => [
                $project->id => $project->members->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                ])->all(),
            ])
            ->all();
    }

    /** @return array<int, array<int, array{id: int, project_id: int, code: string, title: string}>> */
    private function projectRequirements(User $user): array
    {
        return Project::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->with(['requirements' => fn ($requirements) => $requirements
                ->whereNull('archived_at')
                ->orderBy('code')])
            ->get(['id'])
            ->mapWithKeys(fn (Project $project): array => [
                $project->id => $project->requirements->map(fn (Requirement $requirement): array => [
                    'id' => $requirement->id,
                    'project_id' => $requirement->project_id,
                    'code' => $requirement->code,
                    'title' => $requirement->title,
                ])->all(),
            ])
            ->all();
    }

    /** @param array<int, true> $manageableProjectIds */
    private function withCapabilities(Task $task, User $user, array $manageableProjectIds): Task
    {
        $canManage = isset($manageableProjectIds[$task->project_id]);
        $isArchived = $task->archived_at !== null;

        $task->setAttribute('can_update', ! $isArchived && $canManage);
        $task->setAttribute('can_update_status', ! $isArchived && ($canManage || $task->assignee_id === $user->id));
        $task->setAttribute('can_archive', ! $isArchived && $canManage);
        $task->setAttribute('can_restore', $isArchived && $canManage);

        return $task;
    }

    /** @return array<int, true> */
    private function manageableProjectIds(User $user): array
    {
        $query = Project::query()->visibleTo($user)->whereNull('archived_at');

        if ($user->global_role !== 'admin') {
            $query->where(function ($projects) use ($user): void {
                $projects->where('manager_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members
                        ->whereKey($user->id)
                        ->where('project_members.project_role', 'manager')
                        ->where('project_members.status', 'active'));
            });
        }

        return array_fill_keys($query->pluck('id')->all(), true);
    }
}
