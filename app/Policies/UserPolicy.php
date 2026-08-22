<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && $user->archived_at === null;
    }

    public function create(User $user): bool
    {
        return $user->global_role === 'admin' && $user->status === 'active' && $user->archived_at === null;
    }

    public function update(User $user, User $member): bool
    {
        return $user->global_role === 'admin' && $user->status === 'active' && $user->archived_at === null && $member->archived_at === null;
    }

    public function archive(User $user, User $member): bool
    {
        return $user->global_role === 'admin' && $user->status === 'active' && $user->archived_at === null && $user->isNot($member);
    }

    public function restore(User $user, User $member): bool
    {
        return $user->global_role === 'admin' && $user->status === 'active' && $user->archived_at === null && $member->archived_at !== null;
    }
}
