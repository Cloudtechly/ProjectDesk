<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

class IssuePolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function view(User $user, Issue $issue): bool
    {
        return $user->can('view', $issue->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->archived_at === null && $user->can('update', $project);
    }

    public function update(User $user, Issue $issue): bool
    {
        return $issue->archived_at === null
            && $issue->project->archived_at === null
            && $user->can('update', $issue->project);
    }

    public function delete(User $user, Issue $issue): bool
    {
        return false;
    }

    public function archive(User $user, Issue $issue): bool
    {
        return $this->update($user, $issue);
    }

    public function restore(User $user, Issue $issue): bool
    {
        return $issue->archived_at !== null
            && $issue->project->archived_at === null
            && $user->can('update', $issue->project);
    }
}
