<?php

namespace App\Policies;

use App\Models\DataJob;
use App\Models\User;

class DataJobPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->global_role === 'admin';
    }

    public function view(User $user, DataJob $dataJob): bool
    {
        return $user->global_role === 'admin';
    }

    public function create(User $user): bool
    {
        return $user->global_role === 'admin';
    }

    public function commit(User $user, DataJob $dataJob): bool
    {
        return $user->global_role === 'admin' && $dataJob->type === 'import';
    }
}
