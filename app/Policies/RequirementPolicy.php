<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Requirement;
use App\Models\User;

class RequirementPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function view(User $user, Requirement $requirement): bool
    {
        return $user->can('view', $requirement->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->archived_at === null && $user->can('update', $project);
    }

    public function update(User $user, Requirement $requirement): bool
    {
        return $requirement->archived_at === null
            && $requirement->project->archived_at === null
            && $user->can('update', $requirement->project);
    }

    public function archive(User $user, Requirement $requirement): bool
    {
        return $this->update($user, $requirement);
    }

    public function restore(User $user, Requirement $requirement): bool
    {
        return $requirement->archived_at !== null
            && $requirement->project->archived_at === null
            && $user->can('update', $requirement->project);
    }

    public function delete(User $user, Requirement $requirement): bool
    {
        return false;
    }
}
