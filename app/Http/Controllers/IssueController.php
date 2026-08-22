<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveIssueRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class IssueController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $issues = $project->issues()
            ->with('owner:id,name')
            ->when(
                $request->boolean('archived'),
                fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at'),
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'medium' then 3 else 4 end")
            ->paginate(min(max($request->integer('per_page', 30), 1), 100))
            ->withQueryString();

        return response()->json($issues);
    }

    public function show(Project $project, Issue $issue): JsonResponse
    {
        abort_unless($issue->project_id === $project->id, 404);
        $this->authorize('view', $issue);

        return response()->json(['data' => $issue->load('owner:id,name')]);
    }

    public function store(
        SaveIssueRequest $request,
        Project $project,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $issue = DB::transaction(function () use ($request, $project, $user, $logger): Issue {
            $issue = $project->issues()->create([
                ...$request->validated(),
                'lock_version' => 1,
            ]);
            $logger->record($issue, 'issue.created', $user, after: $issue->toArray(), request: $request);

            return $issue->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $issue], 201)
                ->header('Location', route('projects.issues.show', [$project, $issue]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تسجيل المشكلة.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'issues']);
    }

    public function update(
        SaveIssueRequest $request,
        Project $project,
        Issue $issue,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($issue->project_id === $project->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validated();
        $expectedVersion = (int) Arr::pull($validated, 'lock_version');
        $issue = DB::transaction(function () use ($request, $issue, $user, $logger, $validated, $expectedVersion): Issue {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->id);
            OptimisticLock::assertCurrent($locked->lock_version, $expectedVersion);

            $before = $locked->toArray();
            $locked->fill([
                ...$validated,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record($locked, 'issue.updated', $user, $before, $locked->toArray(), $request);

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $issue]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث المشكلة.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'issues']);
    }

    public function archive(
        Request $request,
        Project $project,
        Issue $issue,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($issue->project_id === $project->id, 404);
        $this->authorize('archive', $issue);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $issue = DB::transaction(function () use ($request, $issue, $user, $logger, $validated): Issue {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->id);
            OptimisticLock::assertCurrent($locked->lock_version, (int) $validated['lock_version']);

            $before = $locked->toArray();
            $locked->fill([
                'archived_at' => now(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record($locked, 'issue.archived', $user, $before, $locked->toArray(), $request);

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $issue]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة المشكلة دون حذف سجلها.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'issues']);
    }

    public function restore(
        Request $request,
        Project $project,
        Issue $issue,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($issue->project_id === $project->id, 404);
        $this->authorize('restore', $issue);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $issue = DB::transaction(function () use ($request, $issue, $user, $logger, $validated): Issue {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->id);
            OptimisticLock::assertCurrent($locked->lock_version, (int) $validated['lock_version']);

            $before = $locked->toArray();
            $locked->fill([
                'archived_at' => null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record($locked, 'issue.restored', $user, $before, $locked->toArray(), $request);

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $issue]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة المشكلة.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'issues', 'archived' => 1]);
    }
}
