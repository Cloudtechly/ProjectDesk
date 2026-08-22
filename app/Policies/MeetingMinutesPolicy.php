<?php

namespace App\Policies;

use App\Models\MeetingMinutes;
use App\Models\User;

class MeetingMinutesPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function view(User $user, MeetingMinutes $minutes): bool
    {
        return $user->can('view', $minutes->meeting);
    }

    public function update(User $user, MeetingMinutes $minutes): bool
    {
        return $user->can('update', $minutes->meeting);
    }
}
