<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return false;
        }

        if ($user->global_role === 'viewer' && ! in_array($ability, ['viewAny', 'view'], true)) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && $user->archived_at === null;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->global_role === 'admin' || $project->manager_id === $user->id
            || $project->members()->whereKey($user->id)->wherePivot('status', 'active')->exists();
    }

    public function create(User $user): bool
    {
        return $user->status === 'active' && $user->archived_at === null && in_array($user->global_role, ['admin', 'project_manager'], true);
    }

    public function update(User $user, Project $project): bool
    {
        return $project->archived_at === null && ($user->global_role === 'admin' || $project->manager_id === $user->id
            || $project->members()->whereKey($user->id)->wherePivot('project_role', 'manager')->wherePivot('status', 'active')->exists());
    }

    public function archive(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function restore(User $user, Project $project): bool
    {
        if ($project->archived_at === null) {
            return false;
        }

        if ($user->global_role === 'admin') {
            return true;
        }

        $activeMembership = $project->members()
            ->whereKey($user->id)
            ->wherePivot('status', 'active');

        return $project->manager_id === $user->id
            ? $activeMembership->exists()
            : $activeMembership->wherePivot('project_role', 'manager')->exists();
    }

    public function uploadFile(User $user, Project $project): bool
    {
        if ($project->archived_at !== null || $user->global_role === 'viewer') {
            return false;
        }

        if ($user->global_role === 'admin' || $project->manager_id === $user->id) {
            return true;
        }

        return $project->members()
            ->whereKey($user->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('project_role', ['manager', 'member'])
            ->exists();
    }
}
