<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return false;
        }

        if ($user->global_role === 'viewer' && $ability !== 'view') {
            return false;
        }

        return null;
    }

    public function view(User $user, Task $task): bool
    {
        return $user->can('view', $task->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->archived_at === null && ($user->global_role === 'admin' || $user->can('update', $project));
    }

    public function update(User $user, Task $task): bool
    {
        return $task->archived_at === null
            && $task->project->archived_at === null
            && ($user->global_role === 'admin' || $user->can('update', $task->project));
    }

    public function updateStatus(User $user, Task $task): bool
    {
        return $task->archived_at === null
            && $task->project->archived_at === null
            && ($user->global_role === 'admin' || $user->can('update', $task->project) || $task->assignee_id === $user->id);
    }

    public function delete(User $user, Task $task): bool
    {
        return $task->archived_at === null && $task->project->archived_at === null && ($user->global_role === 'admin' || $user->can('update', $task->project));
    }

    public function restore(User $user, Task $task): bool
    {
        return $task->archived_at !== null
            && $task->project->archived_at === null
            && ($user->global_role === 'admin' || $user->can('update', $task->project));
    }
}
