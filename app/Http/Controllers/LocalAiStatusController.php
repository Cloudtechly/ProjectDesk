<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\LocalEngineStatus;
use Illuminate\Http\JsonResponse;

class LocalAiStatusController extends Controller
{
    public function __invoke(LocalEngineStatus $status): JsonResponse
    {
        $this->authorize('viewAny', SystemSetting::class);

        return response()->json(['data' => $status->get()]);
    }
}
