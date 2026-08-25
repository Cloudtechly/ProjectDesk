<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectLifecycleService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function archive(Project $project, int $lockVersion, User $actor, Request $request): Project
    {
        return $this->changeArchiveState($project, $lockVersion, $actor, $request, true);
    }

    public function restore(Project $project, int $lockVersion, User $actor, Request $request): Project
    {
        return $this->changeArchiveState($project, $lockVersion, $actor, $request, false);
    }

    private function changeArchiveState(
        Project $project,
        int $lockVersion,
        User $actor,
        Request $request,
        bool $archive,
    ): Project {
        return DB::transaction(function () use ($project, $lockVersion, $actor, $request, $archive): Project {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($lockedProject->lock_version !== $lockVersion) {
                abort(409, 'عُدّلت بيانات المشروع في جلسة أخرى. حدّث الصفحة ثم أعد المحاولة.');
            }

            $before = $lockedProject->toArray();
            $lockedProject->update([
                'archived_at' => $archive ? now() : null,
                'lock_version' => $lockedProject->lock_version + 1,
            ]);
            $this->activityLogger->record(
                $lockedProject,
                $archive ? 'project.archived' : 'project.restored',
                $actor,
                $before,
                $lockedProject->toArray(),
                $request,
            );

            return $lockedProject;
        });
    }
}
