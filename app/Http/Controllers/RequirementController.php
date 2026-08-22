<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArchiveRequirementRequest;
use App\Http\Requests\RestoreRequirementRequest;
use App\Http\Requests\SaveRequirementRequest;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\User;
use App\Services\RequirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RequirementController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $requirements = $project->requirements()
            ->with(['status:id,label,color,semantic', 'owner:id,name'])
            ->when(! $request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('code')
            ->paginate(min(max($request->integer('per_page', 30), 1), 100))
            ->withQueryString();

        return response()->json($requirements);
    }

    public function show(Project $project, Requirement $requirement): JsonResponse
    {
        abort_unless($requirement->project_id === $project->id, 404);
        $this->authorize('view', $requirement);

        return response()->json(['data' => $requirement->load(['status', 'owner', 'tasks:id,code,title'])]);
    }

    public function store(
        SaveRequirementRequest $request,
        Project $project,
        RequirementService $service,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requirement = $service->create($project->id, $request->validated(), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $requirement], 201)
                ->header('Location', route('projects.requirements.show', [$project, $requirement]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت إضافة المتطلب بنجاح.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'requirements']);
    }

    public function update(
        SaveRequirementRequest $request,
        Project $project,
        Requirement $requirement,
        RequirementService $service,
    ): JsonResponse|RedirectResponse {
        abort_unless($requirement->project_id === $project->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requirement = $service->update($requirement, $request->validated(), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $requirement]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث المتطلب بنجاح.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'requirements']);
    }

    public function archive(
        ArchiveRequirementRequest $request,
        Project $project,
        Requirement $requirement,
        RequirementService $service,
    ): JsonResponse|RedirectResponse {
        abort_unless($requirement->project_id === $project->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requirement = $service->archive($requirement, $request->integer('lock_version'), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $requirement]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة المتطلب مع الاحتفاظ بسجله.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'requirements']);
    }

    public function restore(
        RestoreRequirementRequest $request,
        Project $project,
        Requirement $requirement,
        RequirementService $service,
    ): JsonResponse|RedirectResponse {
        abort_unless($requirement->project_id === $project->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requirement = $service->restore($requirement, $request->integer('lock_version'), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $requirement]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة المتطلب وأصبح نشطاً من جديد.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'requirements']);
    }
}
