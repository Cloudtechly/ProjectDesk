<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRiskRequest;
use App\Models\Project;
use App\Models\Risk;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RiskController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $risks = $project->risks()
            ->with('owner:id,name')
            ->when(
                $request->boolean('archived'),
                fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at'),
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc(DB::raw('probability * impact'))
            ->paginate(min(max($request->integer('per_page', 30), 1), 100))
            ->withQueryString();

        return response()->json($risks);
    }

    public function show(Project $project, Risk $risk): JsonResponse
    {
        abort_unless($risk->project_id === $project->id, 404);
        $this->authorize('view', $risk);

        return response()->json(['data' => $risk->load('owner:id,name')]);
    }

    public function store(
        SaveRiskRequest $request,
        Project $project,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $risk = DB::transaction(function () use ($request, $project, $user, $logger): Risk {
            $risk = $project->risks()->create([
                ...$request->validated(),
                'lock_version' => 1,
            ]);
            $logger->record($risk, 'risk.created', $user, after: $risk->toArray(), request: $request);

            return $risk->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $risk], 201)
                ->header('Location', route('projects.risks.show', [$project, $risk]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تسجيل المخاطرة.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'risks']);
    }

    public function update(
        SaveRiskRequest $request,
        Project $project,
        Risk $risk,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($risk->project_id === $project->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validated();
        $expectedVersion = (int) Arr::pull($validated, 'lock_version');
        $risk = DB::transaction(function () use ($request, $risk, $user, $logger, $validated, $expectedVersion): Risk {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            OptimisticLock::assertCurrent($locked->lock_version, $expectedVersion);

            $before = $locked->toArray();
            $locked->fill([
                ...$validated,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record($locked, 'risk.updated', $user, $before, $locked->toArray(), $request);

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $risk]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث المخاطرة.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'risks']);
    }

    public function archive(
        Request $request,
        Project $project,
        Risk $risk,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($risk->project_id === $project->id, 404);
        $this->authorize('archive', $risk);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $risk = DB::transaction(function () use ($request, $risk, $user, $logger, $validated): Risk {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            OptimisticLock::assertCurrent($locked->lock_version, (int) $validated['lock_version']);

            $before = $locked->toArray();
            $locked->fill([
                'archived_at' => now(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record($locked, 'risk.archived', $user, $before, $locked->toArray(), $request);

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $risk]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة المخاطرة دون حذف سجلها.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'risks']);
    }

    public function restore(
        Request $request,
        Project $project,
        Risk $risk,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($risk->project_id === $project->id, 404);
        $this->authorize('restore', $risk);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $risk = DB::transaction(function () use ($request, $risk, $user, $logger, $validated): Risk {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            OptimisticLock::assertCurrent($locked->lock_version, (int) $validated['lock_version']);

            $before = $locked->toArray();
            $locked->fill([
                'archived_at' => null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record($locked, 'risk.restored', $user, $before, $locked->toArray(), $request);

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $risk]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة المخاطرة.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'risks', 'archived' => 1]);
    }
}
