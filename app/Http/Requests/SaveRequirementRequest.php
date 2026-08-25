<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Requirement;
use App\Services\RequirementTaxonomyService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveRequirementRequest extends ProjectResourceRequest
{
    public function authorize(): bool
    {
        $project = $this->routeProject();
        $requirement = $this->route('requirement');

        if (! $project instanceof Project) {
            return false;
        }

        if ($requirement instanceof Requirement) {
            return $requirement->project_id === $project->id && Gate::allows('update', $requirement);
        }

        return Gate::allows('create', [Requirement::class, $project]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $project = $this->routeProject();
        $requirement = $this->route('requirement');

        return [
            'code' => [
                'nullable',
                'string',
                'max:40',
                Rule::unique('requirements', 'code')
                    ->where('project_id', $project?->id)
                    ->ignore($requirement instanceof Requirement ? $requirement->id : null),
            ],
            'title' => ['required', 'string', 'max:255'],
            'group_id' => ['nullable', 'integer', 'exists:requirement_groups,id'],
            'timeline_entry_ids' => ['nullable', 'array', 'max:200'],
            'timeline_entry_ids.*' => ['integer', 'distinct', 'exists:timeline_entries,id'],
            'timeline_links_submitted' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:20000'],
            'acceptance_criteria' => ['nullable', 'string', 'max:50000'],
            'type' => ['sometimes', Rule::in(RequirementTaxonomyService::TYPES)],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status_id' => [
                'required',
                'integer',
                Rule::exists('workflow_statuses', 'id')
                    ->where('entity_type', 'requirement')
                    ->where('is_active', true),
            ],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'lock_version' => [$requirement instanceof Requirement ? 'required' : 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->routeProject();
            if (! $project instanceof Project || ! $this->filled('owner_id')) {
                // Group validation still applies when no owner is selected.
            } elseif (! $this->isActiveProjectMember($project, $this->integer('owner_id'))) {
                $validator->errors()->add('owner_id', 'مالك المتطلب يجب أن يكون عضواً نشطاً في فريق المشروع.');
            }

            if ($project instanceof Project && $this->filled('group_id')
                && ! $project->requirementGroups()->whereKey($this->integer('group_id'))->exists()) {
                $validator->errors()->add('group_id', 'المجموعة المختارة لا تتبع هذا المشروع.');
            }

            if ($project instanceof Project) {
                $timelineIds = array_map('intval', $this->input('timeline_entry_ids', []));
                $validCount = $project->timelineEntries()->whereIn('id', $timelineIds)
                    ->whereIn('kind', ['phase', 'milestone'])->whereNull('archived_at')->count();
                if ($validCount !== count(array_unique($timelineIds))) {
                    $validator->errors()->add('timeline_entry_ids', 'تحتوي الروابط على مرحلة أو معلم لا يتبع هذا المشروع.');
                }
            }
        }];
    }
}
