<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    public function index(Request $request, SystemSettingsService $settings): JsonResponse
    {
        $this->authorize('viewAny', SystemSetting::class);

        return response()->json(['data' => $settings->all()]);
    }

    public function update(
        UpdateSystemSettingsRequest $request,
        string $group,
        SystemSettingsService $settings,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response()->json([
            'data' => $settings->update($group, $request->validated(), $actor, $request),
        ]);
    }

    public function destroy(
        Request $request,
        string $group,
        SystemSettingsService $settings,
    ): JsonResponse {
        $this->authorize('delete', new SystemSetting);
        abort_unless(SystemSettingsService::supportsGroup($group), 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response()->json([
            'data' => $settings->reset($group, $actor, $request),
        ]);
    }
}
