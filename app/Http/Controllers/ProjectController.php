<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeProjectArchiveStateRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ProjectIndexData;
use App\Services\ProjectLifecycleService;
use App\Services\ProjectTeamService;
use App\Services\ProjectWorkspaceData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request, ProjectIndexData $indexData): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorize('viewAny', Project::class);

        return Inertia::render('projects/index', $indexData->for($request, $user));
    }

    public function store(
        StoreProjectRequest $request,
        ActivityLogger $activityLogger,
        ProjectTeamService $teamService,
    ): RedirectResponse {
        $this->authorize('create', Project::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $project = DB::transaction(function () use ($request, $user, $activityLogger, $teamService): Project {
            $validated = $request->validated();
            $members = Arr::pull($validated, 'members');
            $memberIds = Arr::pull($validated, 'member_ids', []);
            $project = Project::query()->create($validated);

            $teamService->sync($project, $members, $memberIds, $user->id);
            $activityLogger->record($project, 'project.created', $user, after: [
                ...$project->toArray(),
                'members' => $teamService->snapshot($project),
            ], request: $request);

            return $project;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم إنشاء المشروع بنجاح. يمكنك إضافة المهام الآن أو لاحقاً.']);

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project, ProjectWorkspaceData $workspaceData): Response
    {
        $this->authorize('view', $project);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('projects/show', $workspaceData->for($request, $project, $user));
    }

    public function archive(
        ChangeProjectArchiveStateRequest $request,
        Project $project,
        ProjectLifecycleService $lifecycleService,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $lifecycleService->archive($project, $request->integer('lock_version'), $user, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة المشروع مع الاحتفاظ بسجله.']);

        return to_route('projects.index');
    }

    public function restore(
        ChangeProjectArchiveStateRequest $request,
        Project $project,
        ProjectLifecycleService $lifecycleService,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $restoredProject = $lifecycleService->restore($project, $request->integer('lock_version'), $user, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة المشروع وأصبح نشطاً من جديد.']);

        return to_route('projects.show', $restoredProject);
    }

    public function update(
        UpdateProjectRequest $request,
        Project $project,
        ActivityLogger $activityLogger,
        ProjectTeamService $teamService,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $project, $user, $activityLogger, $teamService): void {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($lockedProject->lock_version !== $request->integer('lock_version')) {
                abort(409, 'عُدّلت بيانات المشروع في جلسة أخرى. حدّث الصفحة ثم أعد المحاولة.');
            }

            $before = [
                ...$lockedProject->toArray(),
                'members' => $teamService->snapshot($lockedProject),
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
                $teamService->sync($lockedProject, $members, $memberIds);
            } else {
                $teamService->ensureManagerMembership($lockedProject);
            }

            $activityLogger->record($lockedProject, 'project.updated', $user, $before, [
                ...$lockedProject->fresh()->toArray(),
                'members' => $teamService->snapshot($lockedProject),
            ], $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث المشروع بنجاح.']);

        return back();
    }
}
