<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Risk;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveRiskRequest extends ProjectResourceRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBusinessDates(['due_at']);
    }

    public function authorize(): bool
    {
        $project = $this->routeProject();
        $risk = $this->route('risk');
        if (! $project instanceof Project) {
            return false;
        }

        return $risk instanceof Risk
            ? $risk->project_id === $project->id && Gate::allows('update', $risk)
            : Gate::allows('create', [Risk::class, $project]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $risk = $this->route('risk');

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'probability' => ['required', 'integer', 'between:1,5'],
            'impact' => ['required', 'integer', 'between:1,5'],
            'status' => ['required', Rule::in(['open', 'monitoring', 'mitigated', 'accepted', 'closed'])],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'mitigation' => ['nullable', 'string', 'max:50000'],
            'due_at' => ['nullable', 'date'],
            'lock_version' => [$risk instanceof Risk ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->routeProject();
            if ($project instanceof Project && $this->filled('owner_id')
                && ! $this->isActiveProjectMember($project, $this->integer('owner_id'))) {
                $validator->errors()->add('owner_id', 'مالك المخاطرة يجب أن يكون عضواً نشطاً في فريق المشروع.');
            }
        }];
    }
}
