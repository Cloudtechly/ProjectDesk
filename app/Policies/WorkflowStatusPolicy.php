<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowStatus;

class WorkflowStatusPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->global_role === 'admin';
    }

    public function update(User $user, WorkflowStatus $workflowStatus): bool
    {
        return $user->global_role === 'admin';
    }

    public function delete(User $user, WorkflowStatus $workflowStatus): bool
    {
        return false;
    }
}
