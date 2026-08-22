<?php

namespace App\Http\Requests;

use App\Models\Meeting;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveMeetingRequest extends ProjectResourceRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBusinessDates(['starts_at', 'ends_at']);
    }

    public function authorize(): bool
    {
        $project = $this->routeProject();
        $meeting = $this->route('meeting');
        if (! $project instanceof Project) {
            return false;
        }

        return $meeting instanceof Meeting
            ? $meeting->timelineEntry->project_id === $project->id && Gate::allows('update', $meeting)
            : Gate::allows('create', [Meeting::class, $project]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $meeting = $this->route('meeting');

        return [
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'status' => ['required', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
            'organizer_id' => ['nullable', 'integer', 'exists:users,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url:http,https', 'max:2048'],
            'agenda' => ['nullable', 'string', 'max:50000'],
            'note' => ['nullable', 'string', 'max:50000'],
            'attendees' => ['array'],
            'attendees.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'attendees.*.attendance_status' => [
                'required',
                Rule::in(['invited', 'accepted', 'declined', 'tentative', 'attended', 'absent']),
            ],
            'lock_version' => [$meeting instanceof Meeting ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->routeProject();
            if (! $project instanceof Project) {
                return;
            }

            if ($this->filled('organizer_id')
                && ! $this->isActiveProjectMember($project, $this->integer('organizer_id'))) {
                $validator->errors()->add('organizer_id', 'منظم الاجتماع يجب أن يكون عضواً نشطاً في فريق المشروع.');
            }

            $attendees = $this->input('attendees', []);
            if (! is_array($attendees)) {
                return;
            }

            foreach ($attendees as $index => $attendee) {
                if (! is_array($attendee) || ! isset($attendee['user_id'])) {
                    continue;
                }

                $userId = filter_var($attendee['user_id'], FILTER_VALIDATE_INT);
                if ($userId === false || ! $this->isActiveProjectMember($project, $userId)) {
                    $validator->errors()->add("attendees.{$index}.user_id", 'كل حاضر يجب أن يكون عضواً نشطاً في فريق المشروع.');
                }
            }
        }];
    }
}
