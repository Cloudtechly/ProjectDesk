<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\RequirementBookVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateRequirementBookVersionRequest extends ProjectResourceRequest
{
    public function authorize(): bool
    {
        $project = $this->routeProject();
        $version = $this->route('requirementBookVersion');

        return $project instanceof Project
            && $version instanceof RequirementBookVersion
            && $version->requirementBook->project_id === $project->id
            && Gate::allows('update', $version);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'under_review', 'approved', 'superseded'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'is_current' => ['sometimes', 'boolean'],
        ];
    }
}
