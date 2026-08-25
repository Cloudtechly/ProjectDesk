<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTimelineEntryRequest;
use App\Models\Project;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TimelineEntryController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $entries = $project->timelineEntries()
            ->with(['owner:id,name', 'meeting.organizer:id,name', 'meeting.attendees:id,name', 'meeting.minutes'])
            ->when(
                $request->boolean('archived'),
                fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at'),
            )
            ->when($request->filled('kind'), fn ($query) => $query->where('kind', $request->string('kind')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->where('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('starts_at', '<=', $request->date('to')))
            ->orderBy('starts_at')
            ->paginate(min(max($request->integer('per_page', 50), 1), 100))
            ->withQueryString();

        return response()->json($entries);
    }

    public function show(Project $project, TimelineEntry $timelineEntry): JsonResponse
    {
        abort_unless($timelineEntry->project_id === $project->id, 404);
        $this->authorize('view', $timelineEntry);

        return response()->json([
            'data' => $timelineEntry->load(['owner:id,name', 'meeting.organizer:id,name', 'meeting.attendees:id,name', 'meeting.minutes']),
        ]);
    }

    public function store(
        SaveTimelineEntryRequest $request,
        Project $project,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $entry = DB::transaction(function () use ($request, $project, $user, $logger): TimelineEntry {
            $entry = $project->timelineEntries()->create([
                ...$request->validated(),
                'completed_at' => $request->validated('status') === 'completed' ? now() : null,
                'completed_by' => $request->validated('status') === 'completed' ? $user->id : null,
                'lock_version' => 1,
            ]);
            $logger->record($entry, 'timeline_entry.created', $user, after: $entry->toArray(), request: $request);

            return $entry->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $entry], 201)
                ->header('Location', route('projects.timeline-entries.show', [$project, $entry]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت إضافة البند إلى الخط الزمني.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'timeline']);
    }

    public function update(
        SaveTimelineEntryRequest $request,
        Project $project,
        TimelineEntry $timelineEntry,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($timelineEntry->project_id === $project->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validated();
        $expectedVersion = (int) Arr::pull($validated, 'lock_version');
        $timelineEntry = DB::transaction(function () use ($request, $timelineEntry, $user, $logger, $validated, $expectedVersion): TimelineEntry {
            $locked = TimelineEntry::query()->lockForUpdate()->findOrFail($timelineEntry->id);
            OptimisticLock::assertCurrent($locked->lock_version, $expectedVersion);

            $before = $locked->toArray();
            $locked->fill([
                ...$validated,
                'completed_at' => ($validated['status'] ?? null) === 'completed' ? ($locked->completed_at ?? now()) : null,
                'completed_by' => ($validated['status'] ?? null) === 'completed' ? ($locked->completed_by ?? $user->id) : null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record(
                $locked,
                'timeline_entry.updated',
                $user,
                $before,
                $locked->toArray(),
                $request,
            );

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $timelineEntry]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث بند الخط الزمني.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'timeline']);
    }

    public function archive(
        Request $request,
        Project $project,
        TimelineEntry $timelineEntry,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($timelineEntry->project_id === $project->id, 404);
        $this->authorize('archive', $timelineEntry);
        if ($timelineEntry->kind === 'phase' && $project->progress_mode === 'phases') {
            throw ValidationException::withMessages([
                'phase' => 'إلغاء أو أرشفة مرحلة يتطلب محرر الخطة الجماعي لإعادة توزيع الأوزان.',
            ]);
        }
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $timelineEntry = DB::transaction(function () use ($request, $timelineEntry, $user, $logger, $validated): TimelineEntry {
            $locked = TimelineEntry::query()->lockForUpdate()->findOrFail($timelineEntry->id);
            OptimisticLock::assertCurrent($locked->lock_version, (int) $validated['lock_version']);

            $before = $locked->toArray();
            $locked->fill([
                'archived_at' => now(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record(
                $locked,
                'timeline_entry.archived',
                $user,
                $before,
                $locked->toArray(),
                $request,
            );

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $timelineEntry]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة بند الخط الزمني دون حذف سجله.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'timeline']);
    }

    public function restore(
        Request $request,
        Project $project,
        TimelineEntry $timelineEntry,
        ActivityLogger $logger,
    ): JsonResponse|RedirectResponse {
        abort_unless($timelineEntry->project_id === $project->id, 404);
        $this->authorize('restore', $timelineEntry);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $timelineEntry = DB::transaction(function () use ($request, $timelineEntry, $user, $logger, $validated): TimelineEntry {
            $locked = TimelineEntry::query()->lockForUpdate()->findOrFail($timelineEntry->id);
            OptimisticLock::assertCurrent($locked->lock_version, (int) $validated['lock_version']);

            $before = $locked->toArray();
            $locked->fill([
                'archived_at' => null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $logger->record(
                $locked,
                'timeline_entry.restored',
                $user,
                $before,
                $locked->toArray(),
                $request,
            );

            return $locked->load('owner:id,name');
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $timelineEntry]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة بند الخط الزمني.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'timeline', 'archived' => 1]);
    }
}
