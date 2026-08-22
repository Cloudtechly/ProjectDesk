<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\UserSessionSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorize('viewAny', User::class);
        $projectIds = Project::query()->visibleTo($user)->pluck('id');

        $members = User::query()
            ->when($user->global_role !== 'admin', fn ($query) => $query->whereHas('projects', fn ($projects) => $projects
                ->whereIn('projects.id', $projectIds)
                ->where('project_members.status', 'active')))
            ->withCount([
                'projects as active_projects_count' => fn ($query) => $query
                    ->whereIn('projects.id', $projectIds)
                    ->whereNull('projects.archived_at')
                    ->where('project_members.status', 'active'),
                'assignedTasks as open_tasks_count' => fn ($query) => $query->whereIn('project_id', $projectIds)->whereNull('archived_at')->whereHas('status', fn ($status) => $status->whereNotIn('semantic', ['done', 'cancelled'])),
            ])
            ->with([
                'projects' => fn ($query) => $query
                    ->whereIn('projects.id', $projectIds)
                    ->whereNull('projects.archived_at')
                    ->where('project_members.status', 'active')
                    ->orderBy('projects.name'),
                'assignedTasks' => fn ($query) => $query
                    ->whereIn('project_id', $projectIds)
                    ->whereNull('archived_at')
                    ->whereHas('status', fn ($status) => $status->whereNotIn('semantic', ['done', 'cancelled']))
                    ->with(['project:id,name', 'status:id,label,color,semantic'])
                    ->orderBy('due_at')
                    ->limit(8),
            ])
            ->when($request->string('status')->toString() === 'archived', fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = '%'.$request->string('q')->toString().'%';
                $query->where(fn ($member) => $member->where('name', 'like', $search)->orWhere('email', 'like', $search)->orWhere('job_title', 'like', $search));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'job_title', 'global_role', 'status', 'archived_at'])
            ->each(function (User $member) use ($user): void {
                $member->setAttribute('can_update', $user->can('update', $member));
                $member->setAttribute('can_archive', $user->can('archive', $member));
                $member->setAttribute('can_restore', $user->can('restore', $member));
                $member->assignedTasks->each(function ($task) use ($user): void {
                    $task->setAttribute(
                        'href',
                        $user->can('update', $task)
                            ? route('tasks.edit', $task, false)
                            : route('tasks.index', ['project' => $task->project_id, 'q' => $task->code], false),
                    );
                });
            });

        return Inertia::render('team/index', [
            'members' => $members,
            'filters' => $request->only(['q', 'status']),
            'canManage' => $user->can('create', User::class),
        ]);
    }

    public function store(StoreTeamMemberRequest $request, ActivityLogger $activityLogger): RedirectResponse
    {
        $data = $request->validated();
        unset($data['password_confirmation']);
        $member = User::query()->create($data);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $activityLogger->record($member, 'team_member.created', $actor, after: Arr::except($member->toArray(), ['password']), request: $request);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت إضافة عضو الفريق.']);

        return back();
    }

    public function update(
        UpdateTeamMemberRequest $request,
        User $member,
        ActivityLogger $activityLogger,
        UserSessionSecurity $sessionSecurity,
    ): RedirectResponse {
        $before = $member->toArray();
        $data = $request->validated();
        unset($data['password_confirmation']);
        unset($data['current_password']);
        if (($data['password'] ?? null) === null) {
            unset($data['password']);
        }
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $passwordChanged = array_key_exists('password', $data);
        $emailChanged = isset($data['email']) && $data['email'] !== $member->email;
        $roleChanged = isset($data['global_role']) && $data['global_role'] !== $member->global_role;
        $member->fill($data);
        if ($emailChanged) {
            $member->email_verified_at = null;
        }
        $member->save();
        if ($emailChanged || $passwordChanged || $roleChanged) {
            $currentSession = $actor->is($member) ? $request->session()->getId() : null;
            $sessionSecurity->invalidateFor($member, $currentSession);
            if ($actor->is($member) && $passwordChanged) {
                $sessionSecurity->refreshCurrentPasswordHash($request, $member);
            }
        }
        if ($emailChanged) {
            $member->sendEmailVerificationNotification();
        }
        $activityLogger->record($member, 'team_member.updated', $actor, $before, $member->fresh()->toArray(), $request);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث عضو الفريق.']);

        return back();
    }

    public function archive(Request $request, User $member, ActivityLogger $activityLogger): RedirectResponse
    {
        $this->authorize('archive', $member);
        $before = $member->toArray();
        $member->update(['status' => 'inactive', 'archived_at' => now()]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $activityLogger->record($member, 'team_member.archived', $actor, $before, $member->fresh()->toArray(), $request);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة العضو مع الاحتفاظ بارتباطاته.']);

        return back();
    }

    public function restore(Request $request, User $member, ActivityLogger $activityLogger): RedirectResponse
    {
        $this->authorize('restore', $member);
        $before = $member->toArray();
        $member->update(['status' => 'active', 'archived_at' => null]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $activityLogger->record($member, 'team_member.restored', $actor, $before, $member->fresh()->toArray(), $request);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة عضو الفريق.']);

        return back();
    }
}
