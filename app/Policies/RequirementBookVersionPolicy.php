<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\RequirementBookVersion;
use App\Models\User;

class RequirementBookVersionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function view(User $user, RequirementBookVersion $version): bool
    {
        return $user->can('view', $version->requirementBook->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->archived_at === null && $user->can('update', $project);
    }

    public function update(User $user, RequirementBookVersion $version): bool
    {
        return $version->archived_at === null
            && $version->requirementBook->project->archived_at === null
            && $user->can('update', $version->requirementBook->project);
    }

    public function archive(User $user, RequirementBookVersion $version): bool
    {
        return $this->update($user, $version);
    }

    public function restore(User $user, RequirementBookVersion $version): bool
    {
        return $version->archived_at !== null
            && $version->requirementBook->project->archived_at === null
            && $user->can('update', $version->requirementBook->project);
    }
}
