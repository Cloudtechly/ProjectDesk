<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditViewerAssignments extends Command
{
    protected $signature = 'project-desk:audit-viewer-assignments
        {--apply : إصلاح تعيينات المشاريع المخالفة}
        {--actor= : بريد مدير نشط يسجل باسمه الإصلاح عند استخدام --apply}';

    protected $description = 'كشف تعيينات Viewer التنفيذية وإصلاح عضويات المشاريع دون اختراع مدير بديل';

    public function handle(ActivityLogger $logger): int
    {
        $managerProjects = DB::table('projects')
            ->join('users', 'users.id', '=', 'projects.manager_id')
            ->where('users.global_role', 'viewer')
            ->orderBy('projects.id')
            ->get(['projects.id', 'projects.code', 'projects.name', 'users.email']);
        $memberRows = DB::table('project_members')
            ->join('users', 'users.id', '=', 'project_members.user_id')
            ->join('projects', 'projects.id', '=', 'project_members.project_id')
            ->where('users.global_role', 'viewer')
            ->whereIn('project_members.project_role', ['manager', 'member'])
            ->orderBy('projects.id')
            ->get(['projects.id as project_id', 'projects.code', 'users.id as user_id', 'users.email', 'project_members.project_role']);
        $taskRows = DB::table('tasks')
            ->join('users', 'users.id', '=', 'tasks.assignee_id')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->where('users.global_role', 'viewer')
            ->whereNull('tasks.archived_at')
            ->orderBy('projects.id')
            ->get(['projects.code as project_code', 'tasks.code as task_code', 'users.email']);

        $this->table(
            ['المشروع', 'المخالفة', 'المستخدم'],
            [
                ...$managerProjects->map(fn (object $row): array => [$row->code, 'مدير المشروع', $row->email])->all(),
                ...$memberRows->map(fn (object $row): array => [$row->code, "عضوية {$row->project_role}", $row->email])->all(),
                ...$taskRows->map(fn (object $row): array => [$row->project_code, "مسؤول المهمة {$row->task_code}", $row->email])->all(),
            ],
        );

        if (! $this->option('apply')) {
            $this->components->info('انتهى التدقيق دون تعديل. استخدم --apply مع --actor للإصلاح.');

            return self::SUCCESS;
        }

        $actorEmail = trim((string) $this->option('actor'));
        $actor = User::query()
            ->where('email', $actorEmail)
            ->where('global_role', 'admin')
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->first();
        if (! $actor instanceof User) {
            $this->components->error('يتطلب الإصلاح --actor ببريد مدير نشط.');

            return self::FAILURE;
        }

        $affectedProjectIds = $managerProjects->pluck('id')
            ->merge($memberRows->pluck('project_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        DB::transaction(function () use ($affectedProjectIds, $logger, $actor): void {
            foreach ($affectedProjectIds as $projectId) {
                $project = Project::query()->lockForUpdate()->findOrFail($projectId);
                $viewerManager = $project->manager()
                    ->where('global_role', 'viewer')
                    ->exists();
                $violatingMemberIds = DB::table('project_members')
                    ->join('users', 'users.id', '=', 'project_members.user_id')
                    ->where('project_members.project_id', $project->id)
                    ->where('users.global_role', 'viewer')
                    ->whereIn('project_members.project_role', ['manager', 'member'])
                    ->pluck('project_members.user_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
                $before = [
                    'manager_id' => $project->manager_id,
                    'viewer_mutating_member_ids' => $violatingMemberIds,
                ];

                if ($viewerManager) {
                    $project->manager_id = null;
                }
                if ($violatingMemberIds !== []) {
                    DB::table('project_members')
                        ->where('project_id', $project->id)
                        ->whereIn('user_id', $violatingMemberIds)
                        ->update(['project_role' => 'viewer', 'updated_at' => now()]);
                }
                $project->lock_version++;
                $project->save();

                $logger->record($project, 'security.viewer_assignment_repaired', $actor, $before, [
                    'manager_id' => $project->manager_id,
                    'viewer_project_role' => 'viewer',
                ]);
            }
        });

        $this->components->info("أُصلحت {$affectedProjectIds->count()} مشاريع. مهام Viewer المعروضة تحتاج إعادة إسناد يدوي ولا يمنحها النظام صلاحية تعديل.");

        return self::SUCCESS;
    }
}
