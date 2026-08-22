<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\OptimisticLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeetingService
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly ProjectFileService $projectFileService,
    ) {}

    /** @param array<string, mixed> $validated */
    public function create(Project $project, array $validated, User $actor): Meeting
    {
        return DB::transaction(function () use ($project, $validated, $actor): Meeting {
            $attendees = Arr::pull($validated, 'attendees', []);
            $timeline = TimelineEntry::query()->create($this->timelineData($project, $validated));
            $meeting = Meeting::query()->create($this->meetingData($timeline, $validated));
            $meeting->attendees()->sync($this->attendeePivot($attendees));

            $this->activityLogger->record(
                $meeting,
                'meeting.created',
                $actor,
                after: $this->snapshot($meeting),
                request: request(),
            );

            return $meeting->load(['timelineEntry.owner', 'organizer', 'attendees', 'minutes']);
        });
    }

    /** @param array<string, mixed> $validated */
    public function update(Meeting $meeting, Project $project, array $validated, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $project, $validated, $actor): Meeting {
            $expectedVersion = (int) Arr::pull($validated, 'lock_version');
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $timeline = TimelineEntry::query()->lockForUpdate()->findOrFail($locked->timeline_entry_id);
            abort_unless($timeline->project_id === $project->id, 404);
            OptimisticLock::assertCurrent($locked->lock_version, $expectedVersion);
            OptimisticLock::assertCurrent($timeline->lock_version, $expectedVersion);
            $locked->setRelation('timelineEntry', $timeline);

            $before = $this->snapshot($locked);
            $attendees = Arr::pull($validated, 'attendees', []);

            $timeline->fill([
                ...$this->timelineData($project, $validated),
                'lock_version' => $timeline->lock_version + 1,
            ])->save();
            $locked->fill([
                ...$this->meetingData($timeline, $validated),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $locked->attendees()->sync($this->attendeePivot($attendees));

            $this->activityLogger->record($locked, 'meeting.updated', $actor, $before, $this->snapshot($locked), request());

            return $locked->load(['timelineEntry.owner', 'organizer', 'attendees', 'minutes']);
        });
    }

    /** @param array<string, mixed> $validated */
    public function upsertMinutes(Meeting $meeting, Project $project, array $validated, User $actor): MeetingMinutes
    {
        $attachment = Arr::pull($validated, 'attachment');
        $uploadedFile = $attachment instanceof UploadedFile
            ? $this->projectFileService->storeForProject($attachment, $project, $actor, false)
            : null;
        if ($uploadedFile !== null) {
            $validated['file_object_id'] = $uploadedFile->id;
        }

        try {
            return DB::transaction(function () use ($meeting, $project, $validated, $actor): MeetingMinutes {
                $lockedMeeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
                $minutes = MeetingMinutes::query()->where('meeting_id', $meeting->id)->lockForUpdate()->first();
                $before = $minutes?->toArray() ?? [];
                $action = $minutes === null ? 'meeting_minutes.created' : 'meeting_minutes.updated';
                $expectedVersion = Arr::pull($validated, 'lock_version');

                if ($minutes !== null) {
                    OptimisticLock::assertCurrent(
                        $minutes->lock_version,
                        $expectedVersion === null ? 0 : (int) $expectedVersion,
                    );
                }

                if ($lockedMeeting->archived_at !== null) {
                    throw ValidationException::withMessages([
                        'meeting' => 'لا يمكن تعديل محضر اجتماع مؤرشف.',
                    ]);
                }

                $values = [
                    ...$validated,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                    'lock_version' => $minutes === null ? 1 : $minutes->lock_version + 1,
                ];
                if ($minutes === null) {
                    $minutes = MeetingMinutes::query()->create([
                        'meeting_id' => $meeting->id,
                        ...$values,
                    ]);
                } else {
                    $minutes->fill($values)->save();
                }

                DB::table('attachment_links')->where('meeting_minutes_id', $minutes->id)->delete();
                if ($minutes->file_object_id !== null) {
                    $genericLink = DB::table('attachment_links')
                        ->where('file_object_id', $minutes->file_object_id)
                        ->where('project_id', $project->id)
                        ->whereNull('task_id')
                        ->whereNull('requirement_id')
                        ->whereNull('requirement_book_version_id')
                        ->whereNull('meeting_minutes_id')
                        ->whereNull('archived_at')
                        ->first();

                    if ($genericLink !== null) {
                        DB::table('attachment_links')->where('id', $genericLink->id)->update([
                            'meeting_minutes_id' => $minutes->id,
                            'archived_at' => null,
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('attachment_links')->insert([
                            'file_object_id' => $minutes->file_object_id,
                            'project_id' => $project->id,
                            'meeting_minutes_id' => $minutes->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $this->activityLogger->record($minutes, $action, $actor, $before, $minutes->toArray(), request());

                return $minutes->load(['recorder', 'file.uploader:id,name']);
            });
        } catch (Throwable $exception) {
            if ($uploadedFile !== null) {
                $this->projectFileService->discardNewUpload($uploadedFile);
            }

            throw $exception;
        }
    }

    public function archive(Meeting $meeting, int $expectedVersion, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $expectedVersion, $actor): Meeting {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $timeline = TimelineEntry::query()->lockForUpdate()->findOrFail($locked->timeline_entry_id);
            OptimisticLock::assertCurrent($locked->lock_version, $expectedVersion);
            OptimisticLock::assertCurrent($timeline->lock_version, $expectedVersion);
            $locked->setRelation('timelineEntry', $timeline)->load(['attendees', 'minutes']);

            $before = $this->snapshot($locked);
            $archivedAt = now();
            $timeline->update([
                'archived_at' => $archivedAt,
                'lock_version' => $timeline->lock_version + 1,
            ]);
            $locked->update([
                'archived_at' => $archivedAt,
                'lock_version' => $locked->lock_version + 1,
            ]);
            if ($locked->minutes !== null) {
                DB::table('attachment_links')
                    ->where('meeting_minutes_id', $locked->minutes->id)
                    ->update(['archived_at' => $archivedAt, 'updated_at' => now()]);
            }
            $locked->refresh();
            $after = $this->snapshot($locked);
            $this->activityLogger->record($locked, 'meeting.archived', $actor, $before, $after, request());

            return $locked->load(['timelineEntry.owner', 'organizer', 'attendees', 'minutes']);
        });
    }

    public function restore(Meeting $meeting, int $expectedVersion, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $expectedVersion, $actor): Meeting {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $timeline = TimelineEntry::query()->lockForUpdate()->findOrFail($locked->timeline_entry_id);
            OptimisticLock::assertCurrent($locked->lock_version, $expectedVersion);
            OptimisticLock::assertCurrent($timeline->lock_version, $expectedVersion);
            $locked->setRelation('timelineEntry', $timeline)->load(['attendees', 'minutes']);

            $before = $this->snapshot($locked);
            $timeline->update([
                'archived_at' => null,
                'lock_version' => $timeline->lock_version + 1,
            ]);
            $locked->update([
                'archived_at' => null,
                'lock_version' => $locked->lock_version + 1,
            ]);
            if ($locked->minutes !== null) {
                DB::table('attachment_links')
                    ->where('meeting_minutes_id', $locked->minutes->id)
                    ->update(['archived_at' => null, 'updated_at' => now()]);
            }
            $locked->refresh();
            $after = $this->snapshot($locked);
            $this->activityLogger->record($locked, 'meeting.restored', $actor, $before, $after, request());

            return $locked->load(['timelineEntry.owner', 'organizer', 'attendees', 'minutes']);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function timelineData(Project $project, array $validated): array
    {
        return [
            'project_id' => $project->id,
            'kind' => 'meeting',
            'title' => $validated['title'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'status' => $validated['status'],
            'owner_id' => $validated['organizer_id'] ?? null,
            'note' => $validated['note'] ?? null,
            'lock_version' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function meetingData(TimelineEntry $timeline, array $validated): array
    {
        return [
            'timeline_entry_id' => $timeline->id,
            'organizer_id' => $validated['organizer_id'] ?? null,
            'location' => $validated['location'] ?? null,
            'meeting_url' => $validated['meeting_url'] ?? null,
            'agenda' => $validated['agenda'] ?? null,
            'lock_version' => 1,
        ];
    }

    /** @return array<int, array{attendance_status: string}> */
    private function attendeePivot(mixed $attendees): array
    {
        $pivot = [];
        if (! is_array($attendees)) {
            return $pivot;
        }

        foreach ($attendees as $attendee) {
            if (is_array($attendee) && isset($attendee['user_id'], $attendee['attendance_status'])) {
                $pivot[(int) $attendee['user_id']] = ['attendance_status' => (string) $attendee['attendance_status']];
            }
        }

        return $pivot;
    }

    /** @return array<string, mixed> */
    private function snapshot(Meeting $meeting): array
    {
        return $meeting->load(['timelineEntry', 'attendees', 'minutes'])->toArray();
    }
}
