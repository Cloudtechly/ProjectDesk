<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExistingProjectRequest;
use App\Models\User;
use App\Services\ExistingProjectOnboardingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ExistingProjectController extends Controller
{
    public function store(StoreExistingProjectRequest $request, ExistingProjectOnboardingService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $project = $service->create($request->validated(), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم إدخال المشروع القائم وحفظ لقطة الانتقال.']);

        return to_route('projects.show', ['project' => $project, 'onboarding' => 1]);
    }
}
