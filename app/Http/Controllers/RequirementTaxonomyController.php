<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementCategory;
use App\Models\RequirementGroup;
use App\Models\RequirementRelation;
use App\Models\TaxonomyTemplate;
use App\Models\User;
use App\Services\RequirementTaxonomyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RequirementTaxonomyController extends Controller
{
    public function index(Project $project, RequirementTaxonomyService $service): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $service->tree($project)]);
    }

    public function storeCategory(Request $request, Project $project, RequirementTaxonomyService $service): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('requirement_categories')->where('project_id', $project->id)],
            'description' => ['nullable', 'string', 'max:10000'], 'position' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $service->createCategory($project, $data)], 201);
    }

    public function storeGroup(Request $request, Project $project, RequirementCategory $category, RequirementTaxonomyService $service): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('requirement_groups')->where('category_id', $category->id)],
            'description' => ['nullable', 'string', 'max:10000'], 'position' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $service->createGroup($project, $category, $data)], 201);
    }

    public function updateCategory(Request $request, Project $project, RequirementCategory $category): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($category->project_id === $project->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        $category->update($data);

        return response()->json(['data' => $category->fresh('groups')]);
    }

    public function updateGroup(Request $request, Project $project, RequirementGroup $group): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($group->project_id === $project->id, 404);
        $data = $request->validate([
            'category_id' => ['sometimes', 'integer', Rule::exists('requirement_categories', 'id')->where('project_id', $project->id)],
            'name' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        $group->update($data);

        return response()->json(['data' => $group->fresh('category')]);
    }

    public function mergeGroup(Request $request, Project $project, RequirementGroup $group, RequirementTaxonomyService $service): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($group->project_id === $project->id, 404);
        $data = $request->validate([
            'target_group_id' => ['required', 'integer', Rule::exists('requirement_groups', 'id')->where('project_id', $project->id)],
        ]);
        $target = RequirementGroup::query()->whereKey((int) $data['target_group_id'])->firstOrFail();

        return response()->json(['data' => $service->mergeGroups($project, $group, $target)]);
    }

    public function storeRelation(Request $request, Project $project, Requirement $requirement, RequirementTaxonomyService $service): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($requirement->project_id === $project->id, 404);
        $data = $request->validate([
            'target_requirement_id' => ['required', 'integer', Rule::exists('requirements', 'id')->where('project_id', $project->id)],
            'type' => ['required', Rule::in(RequirementTaxonomyService::RELATIONS)],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $target = Requirement::query()->whereKey((int) $data['target_requirement_id'])->firstOrFail();

        return response()->json(['data' => $service->relate($project, $requirement, $target, $data['type'], $user)], 201);
    }

    public function destroyRelation(Request $request, Project $project, RequirementRelation $relation): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($relation->project_id === $project->id, 404);
        $relation->delete();

        return response()->json(status: 204);
    }

    public function applyTemplate(Request $request, Project $project, TaxonomyTemplate $template, RequirementTaxonomyService $service): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($template->is_active, 404);

        return response()->json(['data' => $service->applyTemplate($project, $template)]);
    }
}
