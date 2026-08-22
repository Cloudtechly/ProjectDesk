<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\RequirementBookVersion;
use Illuminate\Support\Facades\Gate;

class ActOnRequirementBookVersionRequest extends ProjectResourceRequest
{
    public function authorize(): bool
    {
        $project = $this->routeProject();
        $version = $this->route('requirementBookVersion');
        $ability = match (true) {
            $this->routeIs('projects.requirement-book.versions.archive') => 'archive',
            $this->routeIs('projects.requirement-book.versions.restore') => 'restore',
            default => 'update',
        };

        return $project instanceof Project
            && $version instanceof RequirementBookVersion
            && $version->requirementBook->project_id === $project->id
            && Gate::allows($ability, $version);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:1']];
    }
}
