<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExistingProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project' => ['required', 'array'],
            'project.code' => ['required', 'string', 'max:40', Rule::unique('projects', 'code')],
            'project.name' => ['required', 'string', 'max:255'],
            'project.description' => ['nullable', 'string'],
            'project.client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'project.primary_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'project.manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'project.status_id' => ['required', 'integer', Rule::exists('workflow_statuses', 'id')->where('entity_type', 'project')],
            'project.priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'project.start_date' => ['required', 'date'],
            'project.end_date' => ['nullable', 'date', 'after_or_equal:project.start_date'],
            'transitioned_at' => ['required', 'date', 'after_or_equal:project.start_date'],
            'members' => ['array'],
            'members.*.id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'members.*.role' => ['required', Rule::in(['manager', 'member', 'viewer'])],
            'phases' => ['required', 'array', 'min:1'],
            'phases.*.title' => ['required', 'string', 'max:255'],
            'phases.*.starts_at' => ['required', 'date'],
            'phases.*.ends_at' => ['required', 'date'],
            'phases.*.status' => ['required', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
            'phases.*.weight_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'phases.*.completion_criteria' => ['nullable', 'string'],
            'phases.*.milestones' => ['array'],
            'phases.*.milestones.*.title' => ['required', 'string', 'max:255'],
            'phases.*.milestones.*.date' => ['required', 'date'],
            'phases.*.milestones.*.status' => ['required', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
            'phases.*.milestones.*.is_gate' => ['required', 'boolean'],
            'tasks' => ['array'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.status_id' => ['required', 'integer', Rule::exists('workflow_statuses', 'id')->where('entity_type', 'task')],
            'tasks.*.priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'tasks.*.phase_index' => ['nullable', 'integer', 'min:0'],
            'tasks.*.assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'tasks.*.start_at' => ['required', 'date'],
            'tasks.*.due_at' => ['required', 'date'],
            'risks' => ['array'],
            'risks.*.title' => ['required', 'string', 'max:255'],
            'risks.*.description' => ['nullable', 'string'],
            'risks.*.probability' => ['required', 'integer', 'between:1,5'],
            'risks.*.impact' => ['required', 'integer', 'between:1,5'],
            'risks.*.status' => ['required', Rule::in(['open', 'monitoring', 'mitigated', 'closed'])],
            'issues' => ['array'],
            'issues.*.title' => ['required', 'string', 'max:255'],
            'issues.*.description' => ['nullable', 'string'],
            'issues.*.severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'issues.*.status' => ['required', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
        ];
    }
}
