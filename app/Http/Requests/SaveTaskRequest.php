<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Requirement;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAssignmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTaskRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $timezone = (string) config('project-desk.business_timezone', 'Africa/Tripoli');
        $dates = [];
        foreach (['assigned_at', 'start_at', 'due_at'] as $field) {
            $value = $this->input($field);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $dates[$field] = CarbonImmutable::parse($value, $timezone)->utc()->format('Y-m-d H:i:s');
        }
        if ($dates !== []) {
            $this->merge($dates);
        }
    }

    public function authorize(): bool
    {
        $task = $this->route('task');
        if ($task instanceof Task) {
            return $this->user()?->can('update', $task) === true;
        }

        $project = Project::query()->find($this->integer('project_id'));

        return $project !== null && $this->user()?->can('create', [Task::class, $project]) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'phase_id' => ['nullable', 'integer', 'exists:timeline_entries,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status_id' => [
                'required',
                'integer',
                Rule::exists('workflow_statuses', 'id')->where('entity_type', 'task')->where('is_active', true),
            ],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_at' => ['nullable', 'date'],
            'start_at' => ['required', 'date'],
            'due_at' => ['required', 'date', 'after_or_equal:start_at'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string'],
            'requirement_ids' => ['array'],
            'requirement_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('requirements', 'id')->whereNull('archived_at'),
            ],
            'assignment_note' => ['nullable', 'string', 'max:2000'],
            'lock_version' => [
                $this->route('task') instanceof Task ? 'required' : 'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = Project::query()->find($this->integer('project_id'));

            if (! $project) {
                return;
            }

            $task = $this->route('task');
            if ($task instanceof Task && $task->project_id !== $project->id) {
                $validator->errors()->add('project_id', 'لا يمكن نقل المهمة إلى مشروع آخر بعد إنشائها.');

                return;
            }

            if ($this->filled('assignee_id')) {
                $assigneeId = $this->integer('assignee_id');
                $isEligibleUser = User::query()
                    ->whereKey($assigneeId)
                    ->where('status', 'active')
                    ->whereNull('archived_at')
                    ->where('global_role', '!=', 'viewer')
                    ->exists();
                $isMember = $isEligibleUser && ($project->manager_id === $assigneeId
                    || $project->members()
                        ->whereKey($assigneeId)
                        ->wherePivot('status', 'active')
                        ->wherePivotIn('project_role', ['manager', 'member'])
                        ->exists());

                if (! $isMember) {
                    $validator->errors()->add('assignee_id', 'المسؤول يجب أن يكون عضواً نشطاً في فريق المشروع.');
                }

                app(ProjectAssignmentGuard::class)->addAssigneeError($validator, $assigneeId);
            }

            if ($this->filled('phase_id')) {
                $validPhase = $project->phases()->whereKey($this->integer('phase_id'))->whereNull('archived_at')->exists();
                if (! $validPhase) {
                    $validator->errors()->add('phase_id', 'المرحلة المختارة لا تتبع هذا المشروع.');
                }
            }

            $requirementIds = array_map('intval', $this->input('requirement_ids', []));
            if ($requirementIds !== []) {
                $validCount = Requirement::query()
                    ->where('project_id', $project->id)
                    ->whereNull('archived_at')
                    ->whereIn('id', $requirementIds)
                    ->count();

                if ($validCount !== count(array_unique($requirementIds))) {
                    $validator->errors()->add('requirement_ids', 'كل المتطلبات المرتبطة يجب أن تتبع المشروع نفسه.');
                }
            }

            if (! $this->filled('assignee_id') && $this->filled('assigned_at')) {
                $validator->errors()->add('assigned_at', 'لا يمكن تسجيل وقت إسناد دون مسؤول.');
            }
        }];
    }
}
