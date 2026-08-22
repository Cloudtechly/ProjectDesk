<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Risk;
use App\Models\User;

class RiskPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function view(User $user, Risk $risk): bool
    {
        return $user->can('view', $risk->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->archived_at === null && $user->can('update', $project);
    }

    public function update(User $user, Risk $risk): bool
    {
        return $risk->archived_at === null
            && $risk->project->archived_at === null
            && $user->can('update', $risk->project);
    }

    public function delete(User $user, Risk $risk): bool
    {
        return false;
    }

    public function archive(User $user, Risk $risk): bool
    {
        return $this->update($user, $risk);
    }

    public function restore(User $user, Risk $risk): bool
    {
        return $risk->archived_at !== null
            && $risk->project->archived_at === null
            && $user->can('update', $risk->project);
    }
}
