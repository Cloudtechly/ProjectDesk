<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\RequirementAnalysisRun;
use App\Models\RequirementBookVersion;
use App\Models\User;
use App\Services\RequirementAnalysisService;
use App\Services\RequirementCandidateApprovalService;
use App\Services\RequirementTaxonomyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RequirementAnalysisController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($project->requirementAnalysisRuns()->withCount('candidates')->latest()->paginate(20));
    }

    public function show(Project $project, RequirementAnalysisRun $analysisRun): JsonResponse
    {
        $this->owned($project, $analysisRun);
        $this->authorize('view', $project);

        return response()->json(['data' => $analysisRun->loadCount(['candidates', 'candidates as pending_candidates_count' => fn ($q) => $q->where('status', 'pending')])]);
    }

    public function store(Request $request, Project $project, RequirementBookVersion $requirementBookVersion, RequirementAnalysisService $service): JsonResponse
    {
        $this->authorize('update', $project);
        $this->versionOwned($project, $requirementBookVersion);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $service->start($project, $requirementBookVersion, $user)], 202);
    }

    public function cancel(Request $request, Project $project, RequirementAnalysisRun $analysisRun, RequirementAnalysisService $service): JsonResponse
    {
        $this->owned($project, $analysisRun);
        $this->authorize('update', $project);

        return response()->json(['data' => $service->cancel($analysisRun)]);
    }

    public function retry(Request $request, Project $project, RequirementAnalysisRun $analysisRun, RequirementAnalysisService $service): JsonResponse
    {
        $this->owned($project, $analysisRun);
        $this->authorize('update', $project);

        return response()->json(['data' => $service->retry($analysisRun)], 202);
    }

    public function override(Request $request, Project $project, RequirementAnalysisRun $analysisRun, RequirementAnalysisService $service): JsonResponse
    {
        $this->owned($project, $analysisRun);
        $this->authorize('update', $project);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $service->overrideSecurityReview($analysisRun, $user)], 202);
    }

    public function candidates(Request $request, Project $project, RequirementAnalysisRun $analysisRun): JsonResponse
    {
        $this->owned($project, $analysisRun);
        $this->authorize('view', $project);

        return response()->json($analysisRun->candidates()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('change_type'), fn ($q) => $q->where('change_type', $request->string('change_type')->toString()))
            ->orderBy('category_name')->orderBy('group_name')->orderBy('title')->paginate(100));
    }

    public function decide(Request $request, Project $project, RequirementAnalysisRun $analysisRun, RequirementCandidateApprovalService $service): JsonResponse
    {
        $this->owned($project, $analysisRun);
        $this->authorize('update', $project);
        $data = $request->validate([
            'decisions' => ['required', 'array', 'min:1', 'max:500'],
            'decisions.*.id' => ['required', 'integer', 'distinct'],
            'decisions.*.action' => ['required', Rule::in(['approve', 'edit_approve', 'reject', 'merge', 'question', 'risk'])],
            'decisions.*.target_requirement_id' => ['nullable', 'integer'],
            'decisions.*.changes' => ['nullable', 'array'],
            'decisions.*.changes.category_name' => ['nullable', 'string', 'max:255'],
            'decisions.*.changes.group_name' => ['nullable', 'string', 'max:255'],
            'decisions.*.changes.title' => ['nullable', 'string', 'max:255'],
            'decisions.*.changes.description' => ['nullable', 'string', 'max:10000'],
            'decisions.*.changes.type' => ['nullable', Rule::in(RequirementTaxonomyService::TYPES)],
            'decisions.*.changes.priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'decisions.*.changes.acceptance_criteria' => ['nullable', 'array'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $service->decide($analysisRun->id, $data['decisions'], $user)]);
    }

    private function owned(Project $project, RequirementAnalysisRun $run): void
    {
        abort_unless($run->project_id === $project->id, 404);
    }

    private function versionOwned(Project $project, RequirementBookVersion $version): void
    {
        abort_unless($version->requirementBook->project_id === $project->id, 404);
    }
}
