<?php

namespace App\Http\Requests;

use App\Models\Meeting;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveMeetingMinutesRequest extends ProjectResourceRequest
{
    public function authorize(): bool
    {
        $project = $this->routeProject();
        $meeting = $this->route('meeting');

        return $project instanceof Project
            && $meeting instanceof Meeting
            && $meeting->timelineEntry->project_id === $project->id
            && Gate::allows('update', $meeting);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $meeting = $this->route('meeting');
        $minutesExist = $meeting instanceof Meeting && $meeting->minutes()->exists();

        return [
            'summary' => ['required', 'string', 'max:100000'],
            'decisions' => ['nullable', 'string', 'max:100000'],
            'action_items' => ['nullable', 'string', 'max:100000'],
            'file_object_id' => [
                'nullable',
                'integer',
                Rule::exists('file_objects', 'id')->where('scan_status', 'safe'),
            ],
            'attachment' => [
                'nullable',
                'file',
                'max:'.(int) config('project-desk.uploads.max_file_kilobytes', 25 * 1024),
            ],
            'lock_version' => [$minutesExist ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->hasFile('attachment') && $this->filled('file_object_id')) {
                $validator->errors()->add('attachment', 'اختر ملفاً مرفوعاً مسبقاً أو ارفع ملفاً جديداً، وليس كليهما.');
                $validator->errors()->add('file_object_id', 'اختر ملفاً مرفوعاً مسبقاً أو ارفع ملفاً جديداً، وليس كليهما.');

                return;
            }

            if (! $this->filled('file_object_id')) {
                return;
            }

            $project = $this->routeProject();
            $user = $this->user();
            if (! $project instanceof Project || $user === null) {
                return;
            }

            $fileId = $this->integer('file_object_id');
            $ownedByActor = DB::table('file_objects')
                ->where('id', $fileId)
                ->where('uploaded_by', $user->id)
                ->exists();
            $alreadyInProject = DB::table('attachment_links')
                ->where('file_object_id', $fileId)
                ->where('project_id', $project->id)
                ->exists();
            $linkedToAnotherProject = DB::table('attachment_links')
                ->where('file_object_id', $fileId)
                ->where('project_id', '!=', $project->id)
                ->exists();

            if (! $alreadyInProject && (! $ownedByActor || $linkedToAnotherProject)) {
                $validator->errors()->add('file_object_id', 'لا يمكن ربط ملف غير مملوك لك أو غير تابع لهذا المشروع.');
            }
        }];
    }
}
