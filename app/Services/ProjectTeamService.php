<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectTeamService
{
    public function sync(Project $project, mixed $members, mixed $legacyMemberIds, ?int $fallbackManagerId = null): void
    {
        $memberPivot = $this->memberPivot($members, $legacyMemberIds);

        if ($project->manager_id !== null) {
            $memberPivot[$project->manager_id] = ['project_role' => 'manager', 'status' => 'active'];
        }

        if ($fallbackManagerId !== null && ! array_key_exists($fallbackManagerId, $memberPivot)) {
            $memberPivot[$fallbackManagerId] = ['project_role' => 'manager', 'status' => 'active'];
        }

        $project->members()->sync($memberPivot);
    }

    public function ensureManagerMembership(Project $project): void
    {
        if ($project->manager_id === null) {
            return;
        }

        $project->members()->syncWithoutDetaching([
            $project->manager_id => ['project_role' => 'manager', 'status' => 'active'],
        ]);
    }

    /** @return list<array{id: int, role: string, status: string}> */
    public function snapshot(Project $project): array
    {
        $snapshot = [];
        $members = DB::table('project_members')
            ->where('project_id', $project->id)
            ->orderBy('user_id')
            ->get(['user_id', 'project_role', 'status']);

        foreach ($members as $member) {
            $snapshot[] = [
                'id' => (int) $member->user_id,
                'role' => (string) $member->project_role,
                'status' => (string) $member->status,
            ];
        }

        return $snapshot;
    }

    /** @return array<int, array{project_role: string, status: string}> */
    private function memberPivot(mixed $members, mixed $legacyMemberIds): array
    {
        $pivot = [];

        if (is_array($members)) {
            foreach ($members as $member) {
                if (! is_array($member) || ! isset($member['id'])) {
                    continue;
                }

                $role = $member['role'] ?? null;
                if (! is_string($role) || ! in_array($role, ['manager', 'member', 'viewer'], true)) {
                    continue;
                }

                $pivot[(int) $member['id']] = ['project_role' => $role, 'status' => 'active'];
            }

            return $pivot;
        }

        if (is_array($legacyMemberIds)) {
            foreach ($legacyMemberIds as $memberId) {
                if (is_int($memberId) || is_string($memberId)) {
                    $pivot[(int) $memberId] = ['project_role' => 'member', 'status' => 'active'];
                }
            }
        }

        return $pivot;
    }
}
