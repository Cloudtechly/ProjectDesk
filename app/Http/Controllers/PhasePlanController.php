<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePhasePlanRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\PhasePlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhasePlanController extends Controller
{
    public function show(Request $request, Project $project, PhasePlanService $service): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $service->summary($project)]);
    }

    public function update(SavePhasePlanRequest $request, Project $project, PhasePlanService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $service->replace($project, $request->validated('phases'), $user)]);
    }
}
