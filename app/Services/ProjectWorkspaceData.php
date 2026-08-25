<?php

namespace App\Services;

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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class ProjectWorkspaceData
{
    public function __construct(private readonly ProjectMetrics $metrics) {}

    /** @return array<string, mixed> */
    public function for(Request $request, Project $project, User $user): array
    {
        $requestedTab = $this->requestedTab($request);
        $archivedRequested = $request->boolean('archived');
        $requirementsArchived = $archivedRequested && $requestedTab === 'requirements';
        $timelineArchived = $archivedRequested && in_array($requestedTab, ['timeline', 'meetings'], true);
        $risksArchived = $archivedRequested && $requestedTab === 'risks';
        $issuesArchived = $archivedRequested && $requestedTab === 'issues';
        $governanceArchived = $requirementsArchived || $timelineArchived || $risksArchived || $issuesArchived;

        $this->loadProjectShell($project);
        $tabPaginator = $this->loadActiveTab(
            $project,
            $requestedTab,
            $requirementsArchived,
            $timelineArchived,
            $risksArchived,
            $issuesArchived,
        );

        $projectIsActive = $project->archived_at === null;
        $canManage = $user->can('update', $project);
        $this->decoratePermissions($project, $user, $canManage, $projectIsActive);

        return [
            'project' => $project,
            'metrics' => $this->metrics->for($project),
            'requirementStatuses' => WorkflowStatus::query()
                ->where('entity_type', 'requirement')
                ->where('is_active', true)
                ->orderBy('position')
                ->get(['id', 'label', 'color', 'semantic']),
            'activity' => $requestedTab === 'activity' ? $this->activity($project) : [],
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
        ];
    }

    private function requestedTab(Request $request): string
    {
        $requestedTab = $request->string('tab')->toString();

        return in_array($requestedTab, [
            'overview', 'requirements', 'tasks', 'timeline', 'meetings', 'risks',
            'issues', 'team', 'documents', 'client', 'activity',
        ], true) ? $requestedTab : 'overview';
    }

    private function loadProjectShell(Project $project): void
    {
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

        $project->setRelation('tasks', collect());
        $project->setRelation('requirements', collect());
        $project->setRelation('timelineEntries', collect());
        $project->setRelation('risks', collect());
        $project->setRelation('issues', collect());
    }

    private function loadActiveTab(
        Project $project,
        string $requestedTab,
        bool $requirementsArchived,
        bool $timelineArchived,
        bool $risksArchived,
        bool $issuesArchived,
    ): mixed {
        $paginator = null;

        if ($requestedTab === 'tasks') {
            $paginator = $project->tasks()
                ->whereNull('archived_at')
                ->with(['status', 'assignee'])
                ->orderBy('due_at')
                ->orderBy('id')
                ->paginate(50, ['*'], 'tab_page')
                ->withQueryString();
            $project->setRelation('tasks', $paginator->getCollection());
        } elseif ($requestedTab === 'requirements') {
            $paginator = $project->requirements()
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
            $project->setRelation('requirements', $paginator->getCollection());
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
            $paginator = $timelineQuery->paginate(50, ['*'], 'tab_page')->withQueryString();
            $project->setRelation('timelineEntries', $paginator->getCollection());
        } elseif ($requestedTab === 'risks') {
            $paginator = $project->risks()
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
            $project->setRelation('risks', $paginator->getCollection());
        } elseif ($requestedTab === 'issues') {
            $paginator = $project->issues()
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
            $project->setRelation('issues', $paginator->getCollection());
        } elseif ($requestedTab === 'overview') {
            $project->setRelation('timelineEntries', $project->timelineEntries()
                ->whereNull('archived_at')
                ->where('starts_at', '>=', Date::now())
                ->orderBy('starts_at')
                ->limit(1)
                ->get());
        }

        return $paginator;
    }

    private function decoratePermissions(Project $project, User $user, bool $canManage, bool $projectIsActive): void
    {
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
    }

    private function activity(Project $project): mixed
    {
        return DB::table('activity_logs')
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
            ->withQueryString();
    }
}
