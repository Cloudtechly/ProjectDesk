<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\TimelineEntry;
use App\Models\User;

class TimelineEntryPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function view(User $user, TimelineEntry $timelineEntry): bool
    {
        return $user->can('view', $timelineEntry->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->archived_at === null && $user->can('update', $project);
    }

    public function update(User $user, TimelineEntry $timelineEntry): bool
    {
        return $timelineEntry->kind !== 'meeting'
            && $timelineEntry->archived_at === null
            && $timelineEntry->project->archived_at === null
            && $user->can('update', $timelineEntry->project);
    }

    public function delete(User $user, TimelineEntry $timelineEntry): bool
    {
        return false;
    }

    public function archive(User $user, TimelineEntry $timelineEntry): bool
    {
        return $this->update($user, $timelineEntry);
    }

    public function restore(User $user, TimelineEntry $timelineEntry): bool
    {
        return $timelineEntry->kind !== 'meeting'
            && $timelineEntry->archived_at !== null
            && $timelineEntry->project->archived_at === null
            && $user->can('update', $timelineEntry->project);
    }
}
