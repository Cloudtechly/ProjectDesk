<?php

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;

class SystemSettingPolicy
{
    public function before(User $user): ?bool
    {
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return false;
        }

        return $user->global_role === 'admin' ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function update(User $user, SystemSetting $systemSetting): bool
    {
        return false;
    }

    public function delete(User $user, SystemSetting $systemSetting): bool
    {
        return false;
    }
}
