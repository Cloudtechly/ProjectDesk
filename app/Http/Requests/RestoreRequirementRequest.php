<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Requirement;
use Illuminate\Support\Facades\Gate;

class RestoreRequirementRequest extends ProjectResourceRequest
{
    public function authorize(): bool
    {
        $project = $this->routeProject();
        $requirement = $this->route('requirement');

        return $project instanceof Project
            && $requirement instanceof Requirement
            && $requirement->project_id === $project->id
            && Gate::allows('restore', $requirement);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:1']];
    }
}
