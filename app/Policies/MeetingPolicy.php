<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;

class MeetingPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $user->can('view', $meeting->timelineEntry->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->archived_at === null && $user->can('update', $project);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $meeting->archived_at === null
            && $meeting->timelineEntry->archived_at === null
            && $meeting->timelineEntry->project->archived_at === null
            && $user->can('update', $meeting->timelineEntry->project);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return false;
    }

    public function archive(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function restore(User $user, Meeting $meeting): bool
    {
        return $meeting->archived_at !== null
            && $meeting->timelineEntry->archived_at !== null
            && $meeting->timelineEntry->project->archived_at === null
            && $user->can('update', $meeting->timelineEntry->project);
    }
}
