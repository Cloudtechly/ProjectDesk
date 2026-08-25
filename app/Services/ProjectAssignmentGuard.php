<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

final class ProjectAssignmentGuard
{
    public const VIEWER_MANAGER_MESSAGE = 'المستخدم ذو الصلاحية العامة «مشاهد» لا يمكن تعيينه مديراً للمشروع.';

    public const VIEWER_MEMBER_MESSAGE = 'المستخدم ذو الصلاحية العامة «مشاهد» لا يمكن منحه دوراً تنفيذياً داخل المشروع.';

    public const VIEWER_ASSIGNEE_MESSAGE = 'المستخدم ذو الصلاحية العامة «مشاهد» للقراءة فقط ولا يمكن إسناد مهمة إليه.';

    /**
     * @param  array<int, mixed>  $members
     * @param  array<int, mixed>  $legacyMemberIds
     */
    public function addProjectErrors(
        Validator $validator,
        mixed $managerId,
        array $members = [],
        array $legacyMemberIds = [],
        string $managerField = 'manager_id',
        string $membersField = 'members',
    ): void {
        $ids = collect([$managerId])
            ->merge(collect($members)->pluck('id'))
            ->merge($legacyMemberIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $users = $this->users($ids);

        if (is_numeric($managerId) && $this->isReadOnlyViewer($users->get((int) $managerId))) {
            $validator->errors()->add($managerField, self::VIEWER_MANAGER_MESSAGE);
        }

        foreach ($members as $index => $member) {
            if (! is_array($member) || ! is_numeric($member['id'] ?? null)) {
                continue;
            }

            $role = $member['role'] ?? null;
            if ($role !== 'viewer' && $this->isReadOnlyViewer($users->get((int) $member['id']))) {
                $validator->errors()->add("{$membersField}.{$index}.role", self::VIEWER_MEMBER_MESSAGE);
            }
        }

        foreach ($legacyMemberIds as $index => $memberId) {
            if (is_numeric($memberId) && $this->isReadOnlyViewer($users->get((int) $memberId))) {
                $validator->errors()->add("member_ids.{$index}", self::VIEWER_MEMBER_MESSAGE);
            }
        }
    }

    public function addAssigneeError(Validator $validator, mixed $assigneeId, string $field = 'assignee_id'): void
    {
        if (! is_numeric($assigneeId)) {
            return;
        }

        $assignee = User::query()->find((int) $assigneeId);
        if ($this->isReadOnlyViewer($assignee)) {
            $validator->errors()->add($field, self::VIEWER_ASSIGNEE_MESSAGE);
        }
    }

    /**
     * Defensive application-layer guard for service calls that do not originate
     * from a FormRequest.
     *
     * @param  array<int, mixed>  $members
     * @param  array<int, mixed>  $assigneeIds
     */
    public function assertAssignments(mixed $managerId, array $members = [], array $assigneeIds = []): void
    {
        $errors = validator([], []);
        $this->addProjectErrors($errors, $managerId, $members);

        foreach ($assigneeIds as $index => $assigneeId) {
            $this->addAssigneeError($errors, $assigneeId, "tasks.{$index}.assignee_id");
        }

        if ($errors->errors()->isNotEmpty()) {
            throw ValidationException::withMessages($errors->errors()->toArray());
        }
    }

    /** @param Collection<int, int> $ids
     * @return Collection<int, User>
     */
    private function users(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereKey($ids)->get()->keyBy('id');
    }

    private function isReadOnlyViewer(?User $user): bool
    {
        return $user instanceof User && $user->global_role === 'viewer';
    }
}
