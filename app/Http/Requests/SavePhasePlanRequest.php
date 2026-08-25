<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePhasePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phases' => ['required', 'array', 'min:1', 'max:100'],
            'phases.*.id' => ['nullable', 'integer'],
            'phases.*.title' => ['required', 'string', 'max:255'],
            'phases.*.starts_at' => ['required', 'date'],
            'phases.*.ends_at' => ['required', 'date'],
            'phases.*.status' => ['required', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
            'phases.*.weight_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'phases.*.completion_criteria' => ['nullable', 'string', 'max:50000'],
            'phases.*.owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'phases.*.note' => ['nullable', 'string', 'max:50000'],
            'phases.*.milestones' => ['array', 'max:100'],
            'phases.*.milestones.*.id' => ['nullable', 'integer'],
            'phases.*.milestones.*.title' => ['required', 'string', 'max:255'],
            'phases.*.milestones.*.date' => ['required', 'date'],
            'phases.*.milestones.*.status' => ['required', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
            'phases.*.milestones.*.is_gate' => ['required', 'boolean'],
            'phases.*.milestones.*.completion_criteria' => ['nullable', 'string', 'max:50000'],
            'phases.*.milestones.*.owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'phases.*.milestones.*.note' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
