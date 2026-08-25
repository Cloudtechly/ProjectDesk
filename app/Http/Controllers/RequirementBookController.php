<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActOnRequirementBookVersionRequest;
use App\Http\Requests\StoreRequirementBookVersionRequest;
use App\Http\Requests\UpdateRequirementBookVersionRequest;
use App\Models\Project;
use App\Models\RequirementBookVersion;
use App\Models\User;
use App\Services\LocalAiSettings;
use App\Services\RequirementAnalysisService;
use App\Services\RequirementBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RequirementBookController extends Controller
{
    public function show(Request $request, Project $project, RequirementBookService $service): JsonResponse
    {
        $this->authorize('view', $project);
        $includeArchived = $request->boolean('include_archived')
            && $request->user()?->can('update', $project) === true;

        return response()->json(['data' => $service->bookData($project, $includeArchived)]);
    }

    public function storeVersion(
        StoreRequirementBookVersionRequest $request,
        Project $project,
        RequirementBookService $service,
        LocalAiSettings $aiSettings,
        RequirementAnalysisService $analysis,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $version = $service->addVersion($project, $request->validated(), $user);
        if ($aiSettings->enabled() && $aiSettings->autoAnalyze()
            && in_array($version->fileObject->extension, ['pdf', 'docx'], true)) {
            $analysis->start($project, $version, $user);
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $service->versionData($version)], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم رفع إصدار كراسة المتطلبات.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    public function updateVersion(
        UpdateRequirementBookVersionRequest $request,
        Project $project,
        RequirementBookVersion $requirementBookVersion,
        RequirementBookService $service,
    ): JsonResponse|RedirectResponse {
        $this->ensureProjectOwnsVersion($project, $requirementBookVersion);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $version = $service->updateVersion($requirementBookVersion, $request->validated(), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $service->versionData($version)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات إصدار الكراسة.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    public function makeCurrent(
        ActOnRequirementBookVersionRequest $request,
        Project $project,
        RequirementBookVersion $requirementBookVersion,
        RequirementBookService $service,
    ): JsonResponse|RedirectResponse {
        $this->ensureProjectOwnsVersion($project, $requirementBookVersion);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $version = $service->makeCurrent(
            $requirementBookVersion,
            (int) $request->validated('lock_version'),
            $user,
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => $service->versionData($version)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تعيين الإصدار الحالي للكراسة.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    public function archiveVersion(
        ActOnRequirementBookVersionRequest $request,
        Project $project,
        RequirementBookVersion $requirementBookVersion,
        RequirementBookService $service,
    ): JsonResponse|RedirectResponse {
        $this->ensureProjectOwnsVersion($project, $requirementBookVersion);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $version = $service->archiveVersion(
            $requirementBookVersion,
            (int) $request->validated('lock_version'),
            $user,
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => $service->versionData($version)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة إصدار الكراسة دون حذف ملفه.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    public function restoreVersion(
        ActOnRequirementBookVersionRequest $request,
        Project $project,
        RequirementBookVersion $requirementBookVersion,
        RequirementBookService $service,
    ): JsonResponse|RedirectResponse {
        $this->ensureProjectOwnsVersion($project, $requirementBookVersion);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $version = $service->restoreVersion(
            $requirementBookVersion,
            (int) $request->validated('lock_version'),
            $user,
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => $service->versionData($version)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة إصدار كراسة المتطلبات ورابط ملفه.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    private function ensureProjectOwnsVersion(Project $project, RequirementBookVersion $version): void
    {
        abort_unless($version->requirementBook->project_id === $project->id, 404);
    }
}
