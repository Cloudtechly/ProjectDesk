<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectOnboardingSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExistingProjectOnboardingService
{
    public function __construct(private readonly PhasePlanService $phasePlans, private readonly ActivityLogger $logger) {}

    /** @param array<string, mixed> $payload */
    public function create(array $payload, User $actor): Project
    {
        return DB::transaction(function () use ($payload, $actor): Project {
            $projectData = $payload['project'];
            $project = Project::query()->create([
                ...$projectData, 'entry_mode' => 'existing', 'progress_mode' => 'phases',
                'transitioned_at' => $payload['transitioned_at'], 'lock_version' => 1,
            ]);
            $members = [];
            foreach ((array) ($payload['members'] ?? []) as $member) {
                $members[(int) $member['id']] = ['project_role' => $member['role'], 'status' => 'active'];
            }
            if ($project->manager_id !== null) {
                $members[$project->manager_id] = ['project_role' => 'manager', 'status' => 'active'];
            }
            $members[$actor->id] ??= ['project_role' => 'manager', 'status' => 'active'];
            $project->members()->sync($members);

            $plan = $this->phasePlans->replace($project, $payload['phases'], $actor);
            $phaseIds = array_column($plan['phases'], 'id');
            foreach ((array) ($payload['tasks'] ?? []) as $taskData) {
                $phaseIndex = Arr::pull($taskData, 'phase_index');
                $status = WorkflowStatus::query()->findOrFail((int) $taskData['status_id']);
                $task = Task::query()->create([
                    ...$taskData, 'project_id' => $project->id,
                    'phase_id' => is_int($phaseIndex) ? ($phaseIds[$phaseIndex] ?? null) : null,
                    'code' => 'PENDING-'.Str::uuid(), 'lock_version' => 1,
                    'completed_at' => $status->semantic === 'done' ? now() : null,
                    'assigned_at' => isset($taskData['assignee_id']) ? now() : null,
                ]);
                $task->forceFill(['code' => 'TSK-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
            }
            foreach ((array) ($payload['risks'] ?? []) as $risk) {
                $project->risks()->create([...$risk, 'lock_version' => 1]);
            }
            foreach ((array) ($payload['issues'] ?? []) as $issue) {
                $project->issues()->create([...$issue, 'lock_version' => 1]);
            }

            $snapshot = [
                'schema_version' => 1, 'transitioned_at' => $payload['transitioned_at'],
                'project' => $project->fresh()->toArray(), 'members' => $members,
                'plan' => $this->phasePlans->summary($project),
                'open_tasks' => $project->tasks()->with('status:id,code,semantic')->get()->toArray(),
                'risks' => $project->risks()->get()->toArray(), 'issues' => $project->issues()->get()->toArray(),
            ];
            $canonical = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            ProjectOnboardingSnapshot::query()->create([
                'project_id' => $project->id, 'snapshot' => $snapshot,
                'snapshot_hash' => hash('sha256', $canonical), 'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->logger->record($project, 'project.existing_onboarded', $actor, after: [
                'snapshot_hash' => hash('sha256', $canonical), 'transitioned_at' => $payload['transitioned_at'],
            ], request: request());

            return $project->fresh();
        });
    }
}
