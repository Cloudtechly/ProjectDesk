<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWorkflowStatusesRequest;
use App\Models\User;
use App\Models\WorkflowStatus;
use App\Services\WorkflowStatusService;
use Illuminate\Http\JsonResponse;

class WorkflowStatusController extends Controller
{
    public function index(
        string $entityType,
        WorkflowStatusService $workflowStatuses,
    ): JsonResponse {
        $this->authorize('viewAny', WorkflowStatus::class);

        return response()->json([
            'data' => [
                'entity_type' => $entityType,
                'statuses' => $workflowStatuses->all($entityType),
            ],
        ]);
    }

    public function update(
        UpdateWorkflowStatusesRequest $request,
        string $entityType,
        WorkflowStatusService $workflowStatuses,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        /** @var list<array{id: int, label: string, semantic: string, color: string, position: int, is_active: bool}> $statuses */
        $statuses = $request->validated('statuses');

        return response()->json([
            'data' => [
                'entity_type' => $entityType,
                'statuses' => $workflowStatuses->update($entityType, $statuses, $actor, $request),
            ],
        ]);
    }
}
