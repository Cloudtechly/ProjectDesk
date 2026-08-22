<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMeetingMinutesRequest;
use App\Http\Requests\SaveMeetingRequest;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Services\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MeetingController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $meetings = Meeting::query()
            ->whereHas('timelineEntry', fn ($query) => $query->where('project_id', $project->id))
            ->when(
                $request->boolean('archived'),
                fn ($query) => $query
                    ->whereNotNull('archived_at')
                    ->whereHas('timelineEntry', fn ($timeline) => $timeline->whereNotNull('archived_at')),
                fn ($query) => $query
                    ->whereNull('archived_at')
                    ->whereHas('timelineEntry', fn ($timeline) => $timeline->whereNull('archived_at')),
            )
            ->with([
                'timelineEntry.owner:id,name', 'organizer:id,name', 'attendees:id,name',
                'minutes.recorder:id,name',
                'minutes.file:id,original_name,mime_type,extension,size_bytes,uploaded_at',
            ])
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->whereHas('timelineEntry', fn ($timeline) => $timeline->where('status', $request->string('status')->toString()));
            })
            ->orderBy(
                TimelineEntry::query()->select('timeline_entries.starts_at')
                    ->from('timeline_entries')
                    ->whereColumn('timeline_entries.id', 'meetings.timeline_entry_id')
                    ->limit(1),
            )
            ->paginate(min(max($request->integer('per_page', 30), 1), 100))
            ->withQueryString();

        return response()->json($meetings);
    }

    public function show(Project $project, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->timelineEntry->project_id === $project->id, 404);
        $this->authorize('view', $meeting);

        return response()->json([
            'data' => $meeting->load([
                'timelineEntry.owner:id,name', 'organizer:id,name', 'attendees:id,name',
                'minutes.recorder:id,name',
                'minutes.file:id,original_name,mime_type,extension,size_bytes,uploaded_at',
            ]),
        ]);
    }

    public function store(
        SaveMeetingRequest $request,
        Project $project,
        MeetingService $service,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $meeting = $service->create($project, $request->validated(), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $meeting], 201)
                ->header('Location', route('projects.meetings.show', [$project, $meeting]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت جدولة الاجتماع وإضافته إلى الخط الزمني.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'meetings']);
    }

    public function update(
        SaveMeetingRequest $request,
        Project $project,
        Meeting $meeting,
        MeetingService $service,
    ): JsonResponse|RedirectResponse {
        abort_unless($meeting->timelineEntry->project_id === $project->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $meeting = $service->update($meeting, $project, $request->validated(), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $meeting]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث الاجتماع والخط الزمني.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'meetings']);
    }

    public function archive(
        Request $request,
        Project $project,
        Meeting $meeting,
        MeetingService $service,
    ): JsonResponse|RedirectResponse {
        abort_unless($meeting->timelineEntry->project_id === $project->id, 404);
        $this->authorize('archive', $meeting);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $meeting = $service->archive($meeting, (int) $validated['lock_version'], $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $meeting]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة الاجتماع ومحضره دون حذف السجل.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'meetings']);
    }

    public function restore(
        Request $request,
        Project $project,
        Meeting $meeting,
        MeetingService $service,
    ): JsonResponse|RedirectResponse {
        abort_unless($meeting->timelineEntry->project_id === $project->id, 404);
        $this->authorize('restore', $meeting);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $meeting = $service->restore($meeting, (int) $validated['lock_version'], $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $meeting]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة الاجتماع إلى الجدول الزمني.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'meetings', 'archived' => 1]);
    }

    public function upsertMinutes(
        SaveMeetingMinutesRequest $request,
        Project $project,
        Meeting $meeting,
        MeetingService $service,
    ): JsonResponse|RedirectResponse {
        abort_unless($meeting->timelineEntry->project_id === $project->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $minutes = $service->upsertMinutes($meeting, $project, $request->validated(), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $minutes]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم حفظ محضر الاجتماع.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'meetings']);
    }
}
