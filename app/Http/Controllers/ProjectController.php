<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeProjectArchiveStateRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Client;
use App\Models\Issue;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Models\WorkflowStatus;
use App\Services\ActivityLogger;
use App\Services\ProjectMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request, ProjectMetrics $metrics): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorize('viewAny', Project::class);

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
                fn ($query) => $metrics->whereHealth($query, $request->string('health')->toString()),
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status_id', $request->integer('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')->toString()))
            ->when($request->filled('client'), fn ($query) => $query->where('client_id', $request->integer('client')))
            ->when(
                $sort === 'priority',
                fn ($query) => $query->orderByRaw("CASE priority WHEN 'low' THEN 1 WHEN 'medium' THEN 2 WHEN 'high' THEN 3 WHEN 'critical' THEN 4 ELSE 5 END {$direction}"),
                fn ($query) => $query->orderBy($sortColumns[$sort], $direction),
            );

        $projects = $metrics->withMetrics($projectsQuery)
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Project $project) use ($metrics, $user): array {
                $projectMetrics = $metrics->fromCounts($project);

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

        return Inertia::render('projects/index', [
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
        ]);
    }

    public function store(StoreProjectRequest $request, ActivityLogger $activityLogger): RedirectResponse
    {
        $this->authorize('create', Project::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $project = DB::transaction(function () use ($request, $user, $activityLogger): Project {
            $validated = $request->validated();
            $members = Arr::pull($validated, 'members');
            $memberIds = Arr::pull($validated, 'member_ids', []);
            $project = Project::query()->create($validated);

            $memberPivot = $this->memberPivot($members, $memberIds);
            if ($project->manager_id !== null) {
                $memberPivot[$project->manager_id] = ['project_role' => 'manager', 'status' => 'active'];
            }
            if (! array_key_exists($user->id, $memberPivot)) {
                $memberPivot[$user->id] = ['project_role' => 'manager', 'status' => 'active'];
            }
            $project->members()->sync($memberPivot);
            $activityLogger->record($project, 'project.created', $user, after: [
                ...$project->toArray(),
                'members' => $this->memberSnapshot($project),
            ], request: $request);

            return $project;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم إنشاء المشروع بنجاح. يمكنك إضافة المهام الآن أو لاحقاً.']);

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project, ProjectMetrics $metrics): Response
    {
        $this->authorize('view', $project);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requestedTab = $request->string('tab')->toString();
        $requestedTab = in_array($requestedTab, [
            'overview', 'requirements', 'tasks', 'timeline', 'meetings', 'risks',
            'issues', 'team', 'documents', 'client', 'activity',
        ], true) ? $requestedTab : 'overview';
        $archivedRequested = $request->boolean('archived');
        $requirementsArchived = $archivedRequested && $requestedTab === 'requirements';
        $timelineArchived = $archivedRequested && in_array($requestedTab, ['timeline', 'meetings'], true);
        $risksArchived = $archivedRequested && $requestedTab === 'risks';
        $issuesArchived = $archivedRequested && $requestedTab === 'issues';
        $governanceArchived = $requirementsArchived || $timelineArchived || $risksArchived || $issuesArchived;
        $project->load([
            'client' => fn ($client) => $client->with(['contacts' => fn ($contacts) => $contacts
                ->where('is_active', true)
                ->orderByDesc('is_primary')
                ->orderBy('name')]),
            'primaryContact', 'status', 'manager',
            'members' => fn ($members) => $members
                ->where('project_members.status', 'active')
                ->where('users.status', 'active')
                ->whereNull('users.archived_at')
                ->orderBy('users.name'),
        ]);
        // A project can contain thousands of records. Only the active tab is
        // loaded and it is paginated; inactive tabs serialize as empty arrays
        // so the existing page contract stays predictable.
        $project->setRelation('tasks', collect());
        $project->setRelation('requirements', collect());
        $project->setRelation('timelineEntries', collect());
        $project->setRelation('risks', collect());
        $project->setRelation('issues', collect());
        $tabPaginator = null;

        if ($requestedTab === 'tasks') {
            $tabPaginator = $project->tasks()
                ->whereNull('archived_at')
                ->with(['status', 'assignee'])
                ->orderBy('due_at')
                ->orderBy('id')
                ->paginate(50, ['*'], 'tab_page')
                ->withQueryString();
            $project->setRelation('tasks', $tabPaginator->getCollection());
        } elseif ($requestedTab === 'requirements') {
            $tabPaginator = $project->requirements()
                ->when(
                    $requirementsArchived,
                    fn ($query) => $query->whereNotNull('archived_at'),
                    fn ($query) => $query->whereNull('archived_at'),
                )
                ->with(['status', 'owner', 'group.category', 'sources', 'timelineEntries:id,title,kind'])
                ->orderBy('code')
                ->orderBy('id')
                ->paginate(50, ['*'], 'tab_page')
                ->withQueryString();
            $project->setRelation('requirements', $tabPaginator->getCollection());
        } elseif (in_array($requestedTab, ['timeline', 'meetings'], true)) {
            $timelineQuery = $project->timelineEntries()
                ->when(
                    $timelineArchived,
                    fn ($query) => $query->whereNotNull('archived_at'),
                    fn ($query) => $query->whereNull('archived_at'),
                )
                ->when($requestedTab === 'meetings', fn ($query) => $query->where('kind', 'meeting'))
                ->with([
                    'owner', 'parentPhase:id,title', 'milestones', 'tasks.status:id,semantic',
                    'meeting' => fn ($meetings) => $meetings->when(
                        $timelineArchived,
                        fn ($query) => $query->whereNotNull('archived_at'),
                        fn ($query) => $query->whereNull('archived_at'),
                    ),
                    'meeting.organizer', 'meeting.attendees', 'meeting.minutes.recorder',
                    'meeting.minutes.file:id,original_name,mime_type,extension,size_bytes,uploaded_at',
                ])
                ->orderBy('starts_at')
                ->orderBy('id');
            $tabPaginator = $timelineQuery->paginate(50, ['*'], 'tab_page')->withQueryString();
            $project->setRelation('timelineEntries', $tabPaginator->getCollection());
        } elseif ($requestedTab === 'risks') {
            $tabPaginator = $project->risks()
                ->when(
                    $risksArchived,
                    fn ($query) => $query->whereNotNull('archived_at'),
                    fn ($query) => $query->whereNull('archived_at'),
                )
                ->with('owner')
                ->orderByDesc(DB::raw('probability * impact'))
                ->orderBy('id')
                ->paginate(50, ['*'], 'tab_page')
                ->withQueryString();
            $project->setRelation('risks', $tabPaginator->getCollection());
        } elseif ($requestedTab === 'issues') {
            $tabPaginator = $project->issues()
                ->when(
                    $issuesArchived,
                    fn ($query) => $query->whereNotNull('archived_at'),
                    fn ($query) => $query->whereNull('archived_at'),
                )
                ->with('owner')
                ->orderBy('due_at')
                ->orderBy('id')
                ->paginate(50, ['*'], 'tab_page')
                ->withQueryString();
            $project->setRelation('issues', $tabPaginator->getCollection());
        } elseif ($requestedTab === 'overview') {
            $nextTimelineEntry = $project->timelineEntries()
                ->whereNull('archived_at')
                ->where('starts_at', '>=', Date::now())
                ->orderBy('starts_at')
                ->limit(1)
                ->get();
            $project->setRelation('timelineEntries', $nextTimelineEntry);
        }
        $projectIsActive = $project->archived_at === null;
        $canManage = $user->can('update', $project);
        $project->tasks->each(function (Task $task) use ($canManage, $projectIsActive, $user): void {
            $taskIsActive = $task->archived_at === null && $projectIsActive;
            $task->setAttribute('can_update', $taskIsActive && $canManage);
            $task->setAttribute('can_update_status', $taskIsActive && ($canManage || $task->assignee_id === $user->id));
        });
        $project->requirements->each(function (Requirement $requirement) use ($canManage, $projectIsActive): void {
            $requirementIsActive = $requirement->archived_at === null && $projectIsActive;
            $requirement->setAttribute('can_update', $requirementIsActive && $canManage);
            $requirement->setAttribute('can_archive', $requirementIsActive && $canManage);
            $requirement->setAttribute(
                'can_restore',
                $requirement->archived_at !== null && $projectIsActive && $canManage,
            );
        });

        $activity = $requestedTab === 'activity' ? DB::table('activity_logs')
            ->leftJoin('users', 'users.id', '=', 'activity_logs.actor_id')
            ->where(function ($query) use ($project): void {
                $query->where('activity_logs.project_id', $project->id)
                    ->orWhere(function ($legacy) use ($project): void {
                        $legacy->whereNull('activity_logs.project_id')
                            ->where(function ($subjects) use ($project): void {
                                $subjects
                                    ->where(function ($subject) use ($project): void {
                                        $subject->where('activity_logs.subject_type', Project::class)
                                            ->where('activity_logs.subject_id', $project->id);
                                    })
                                    ->orWhere(function ($subject) use ($project): void {
                                        $subject->where('activity_logs.subject_type', Task::class)
                                            ->whereIn('activity_logs.subject_id', Task::query()->where('project_id', $project->id)->select('id'));
                                    })
                                    ->orWhere(function ($subject) use ($project): void {
                                        $subject->where('activity_logs.subject_type', Requirement::class)
                                            ->whereIn('activity_logs.subject_id', Requirement::query()->where('project_id', $project->id)->select('id'));
                                    })
                                    ->orWhere(function ($subject) use ($project): void {
                                        $subject->where('activity_logs.subject_type', TimelineEntry::class)
                                            ->whereIn('activity_logs.subject_id', TimelineEntry::query()->where('project_id', $project->id)->select('id'));
                                    })
                                    ->orWhere(function ($subject) use ($project): void {
                                        $subject->where('activity_logs.subject_type', Meeting::class)
                                            ->whereIn('activity_logs.subject_id', Meeting::query()
                                                ->whereHas('timelineEntry', fn ($timeline) => $timeline->where('project_id', $project->id))
                                                ->select('id'));
                                    })
                                    ->orWhere(function ($subject) use ($project): void {
                                        $subject->where('activity_logs.subject_type', Risk::class)
                                            ->whereIn('activity_logs.subject_id', Risk::query()->where('project_id', $project->id)->select('id'));
                                    })
                                    ->orWhere(function ($subject) use ($project): void {
                                        $subject->where('activity_logs.subject_type', Issue::class)
                                            ->whereIn('activity_logs.subject_id', Issue::query()->where('project_id', $project->id)->select('id'));
                                    });
                            });
                    });
            })
            ->latest('activity_logs.created_at')
            ->orderByDesc('activity_logs.id')
            ->paginate(25, [
                'activity_logs.id', 'activity_logs.action', 'activity_logs.subject_type',
                'activity_logs.subject_id', 'activity_logs.request_id', 'activity_logs.correlation_id',
                'activity_logs.created_at', 'users.name as actor',
            ], 'activity_page')
            ->withQueryString() : [];

        return Inertia::render('projects/show', [
            'project' => $project,
            'metrics' => $metrics->for($project),
            'requirementStatuses' => WorkflowStatus::query()
                ->where('entity_type', 'requirement')
                ->where('is_active', true)
                ->orderBy('position')
                ->get(['id', 'label', 'color', 'semantic']),
            'activity' => $activity,
            'tabPagination' => $tabPaginator === null ? null : [
                'current_page' => $tabPaginator->currentPage(),
                'last_page' => $tabPaginator->lastPage(),
                'total' => $tabPaginator->total(),
                'prev_page_url' => $tabPaginator->previousPageUrl(),
                'next_page_url' => $tabPaginator->nextPageUrl(),
            ],
            'projectStatuses' => $canManage
                ? WorkflowStatus::query()->where('entity_type', 'project')->where('is_active', true)->orderBy('position')->get(['id', 'label'])
                : [],
            'clients' => $canManage
                ? Client::query()->manageableBy($user)->whereNull('archived_at')->where('status', 'active')
                    ->with(['contacts' => fn ($contacts) => $contacts->where('is_active', true)->orderByDesc('is_primary')->orderBy('name')])
                    ->orderBy('name')->get(['id', 'name'])
                : [],
            'availableMembers' => $canManage
                ? User::query()->where('status', 'active')->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'global_role'])
                : [],
            'canManage' => $canManage,
            'canArchive' => $user->can('archive', $project),
            'canRestore' => $user->can('restore', $project),
            'canCreateTask' => $user->can('create', [Task::class, $project]),
            'canUploadFile' => $user->can('uploadFile', $project),
            'governanceArchivedMode' => $governanceArchived,
        ]);
    }

    public function archive(ChangeProjectArchiveStateRequest $request, Project $project, ActivityLogger $activityLogger): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $project, $user, $activityLogger): void {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($lockedProject->lock_version !== $request->integer('lock_version')) {
                abort(409, 'عُدّلت بيانات المشروع في جلسة أخرى. حدّث الصفحة ثم أعد المحاولة.');
            }

            $before = $lockedProject->toArray();
            $lockedProject->update([
                'archived_at' => now(),
                'lock_version' => $lockedProject->lock_version + 1,
            ]);
            $activityLogger->record($lockedProject, 'project.archived', $user, $before, $lockedProject->toArray(), $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة المشروع مع الاحتفاظ بسجله.']);

        return to_route('projects.index');
    }

    public function restore(ChangeProjectArchiveStateRequest $request, Project $project, ActivityLogger $activityLogger): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $project, $user, $activityLogger): void {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($lockedProject->lock_version !== $request->integer('lock_version')) {
                abort(409, 'عُدّلت بيانات المشروع في جلسة أخرى. حدّث الصفحة ثم أعد المحاولة.');
            }

            $before = $lockedProject->toArray();
            $lockedProject->update([
                'archived_at' => null,
                'lock_version' => $lockedProject->lock_version + 1,
            ]);
            $activityLogger->record($lockedProject, 'project.restored', $user, $before, $lockedProject->toArray(), $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة المشروع وأصبح نشطاً من جديد.']);

        return to_route('projects.show', $project);
    }

    public function update(UpdateProjectRequest $request, Project $project, ActivityLogger $activityLogger): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $project, $user, $activityLogger): void {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($lockedProject->lock_version !== $request->integer('lock_version')) {
                abort(409, 'عُدّلت بيانات المشروع في جلسة أخرى. حدّث الصفحة ثم أعد المحاولة.');
            }

            $before = [
                ...$lockedProject->toArray(),
                'members' => $this->memberSnapshot($lockedProject),
            ];
            $validated = $request->validated();
            $membersProvided = array_key_exists('members', $validated)
                || array_key_exists('member_ids', $validated);
            $members = Arr::pull($validated, 'members');
            $memberIds = Arr::pull($validated, 'member_ids');
            unset($validated['lock_version']);
            $validated['lock_version'] = $lockedProject->lock_version + 1;
            $lockedProject->update($validated);

            if ($membersProvided) {
                $memberPivot = $this->memberPivot($members, $memberIds);
                if ($lockedProject->manager_id !== null) {
                    $memberPivot[$lockedProject->manager_id] = ['project_role' => 'manager', 'status' => 'active'];
                }
                $lockedProject->members()->sync($memberPivot);
            } elseif ($lockedProject->manager_id !== null) {
                $lockedProject->members()->syncWithoutDetaching([
                    $lockedProject->manager_id => ['project_role' => 'manager', 'status' => 'active'],
                ]);
            }
            $activityLogger->record($lockedProject, 'project.updated', $user, $before, [
                ...$lockedProject->fresh()->toArray(),
                'members' => $this->memberSnapshot($lockedProject),
            ], $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث المشروع بنجاح.']);

        return back();
    }

    /**
     * @return array<int, array{project_role: string, status: string}>
     */
    private function memberPivot(mixed $members, mixed $legacyMemberIds): array
    {
        $pivot = [];

        if (is_array($members)) {
            foreach ($members as $member) {
                if (! is_array($member) || ! isset($member['id'])) {
                    continue;
                }
                $role = $member['role'] ?? null;
                if (! is_string($role) || ! in_array($role, ['manager', 'member', 'viewer'], true)) {
                    continue;
                }
                $pivot[(int) $member['id']] = ['project_role' => $role, 'status' => 'active'];
            }

            return $pivot;
        }

        if (is_array($legacyMemberIds)) {
            foreach ($legacyMemberIds as $memberId) {
                if (is_int($memberId) || is_string($memberId)) {
                    $pivot[(int) $memberId] = ['project_role' => 'member', 'status' => 'active'];
                }
            }
        }

        return $pivot;
    }

    /** @return list<array{id: int, role: string, status: string}> */
    private function memberSnapshot(Project $project): array
    {
        $snapshot = [];
        $members = DB::table('project_members')
            ->where('project_id', $project->id)
            ->orderBy('user_id')
            ->get(['user_id', 'project_role', 'status']);

        foreach ($members as $member) {
            $values = (array) $member;
            $snapshot[] = [
                'id' => (int) $values['user_id'],
                'role' => (string) $values['project_role'],
                'status' => (string) $values['status'],
            ];
        }

        return $snapshot;
    }
}
