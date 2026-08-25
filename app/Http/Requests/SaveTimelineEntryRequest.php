<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\TimelineEntry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTimelineEntryRequest extends ProjectResourceRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBusinessDates(['starts_at', 'ends_at']);
    }

    public function authorize(): bool
    {
        $project = $this->routeProject();
        $entry = $this->route('timelineEntry');
        if (! $project instanceof Project) {
            return false;
        }

        return $entry instanceof TimelineEntry
            ? $entry->project_id === $project->id && Gate::allows('update', $entry)
            : Gate::allows('create', [TimelineEntry::class, $project]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $entry = $this->route('timelineEntry');

        return [
            'kind' => ['required', Rule::in(['milestone', 'delivery', 'review', 'deadline', 'phase', 'event'])],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['required', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
            'parent_phase_id' => ['nullable', 'integer', 'exists:timeline_entries,id'],
            'weight_percent' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'completion_criteria' => ['nullable', 'string', 'max:50000'],
            'is_gate' => ['nullable', 'boolean'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:50000'],
            'metadata' => ['nullable', 'array'],
            'lock_version' => [$entry instanceof TimelineEntry ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->routeProject();
            if ($project instanceof Project && $this->filled('owner_id')
                && ! $this->isActiveProjectMember($project, $this->integer('owner_id'))) {
                $validator->errors()->add('owner_id', 'مالك بند الجدول يجب أن يكون عضواً نشطاً في فريق المشروع.');
            }
            if ($project instanceof Project && $this->filled('parent_phase_id')
                && ! $project->phases()->whereKey($this->integer('parent_phase_id'))->whereNull('archived_at')->exists()) {
                $validator->errors()->add('parent_phase_id', 'المرحلة الأب لا تتبع هذا المشروع.');
            }
            if ($this->input('kind') === 'phase' && ! $this->filled('weight_percent')) {
                $validator->errors()->add('weight_percent', 'وزن المرحلة مطلوب.');
            }
            if ($this->input('kind') !== 'milestone' && $this->boolean('is_gate')) {
                $validator->errors()->add('is_gate', 'صفة المعلم الإلزامي متاحة للـMilestone فقط.');
            }
        }];
    }
}
