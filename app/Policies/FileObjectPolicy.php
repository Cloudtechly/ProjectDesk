<?php

namespace App\Policies;

use App\Models\AttachmentLink;
use App\Models\FileObject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class FileObjectPolicy
{
    public function before(User $user): ?bool
    {
        return $user->status !== 'active' || $user->archived_at !== null ? false : null;
    }

    public function view(User $user, FileObject $fileObject): bool
    {
        if ($user->global_role === 'admin') {
            return true;
        }

        $projectIds = $fileObject->attachmentLinks()
            ->whereNull('archived_at')
            ->pluck('project_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $projectIds !== []
            && Project::query()->visibleTo($user)->whereKey($projectIds)->exists();
    }

    public function download(User $user, FileObject $fileObject): bool
    {
        return $fileObject->scan_status === 'safe'
            && $fileObject->attachmentLinks()->whereNull('archived_at')->exists()
            && $this->view($user, $fileObject);
    }

    public function archiveAttachment(
        User $user,
        FileObject $fileObject,
        Project $project,
        ?AttachmentLink $attachmentLink = null,
    ): bool {
        return $this->hasAttachmentLink($fileObject, $project, $attachmentLink, false)
            && $user->can('update', $project);
    }

    public function restoreAttachment(
        User $user,
        FileObject $fileObject,
        Project $project,
        ?AttachmentLink $attachmentLink = null,
    ): bool {
        return $this->hasAttachmentLink($fileObject, $project, $attachmentLink, true)
            && $user->can('update', $project);
    }

    private function hasAttachmentLink(
        FileObject $fileObject,
        Project $project,
        ?AttachmentLink $attachmentLink,
        bool $archived,
    ): bool {
        $query = $fileObject->attachmentLinks()
            ->where('project_id', $project->id)
            ->whereNull('requirement_book_version_id')
            ->whereNull('meeting_minutes_id')
            ->when(
                $archived,
                fn (Builder $links): Builder => $links->whereNotNull('archived_at'),
                fn (Builder $links): Builder => $links->whereNull('archived_at'),
            );

        if ($attachmentLink === null) {
            return $query->whereNull('task_id')->whereNull('requirement_id')->exists();
        }

        return $query
            ->whereKey($attachmentLink->id)
            ->where(function (Builder $links): void {
                $links->where(function (Builder $target): void {
                    $target->whereNull('task_id')->whereNull('requirement_id');
                })->orWhere(function (Builder $target): void {
                    $target->whereNotNull('task_id')->whereNull('requirement_id');
                })->orWhere(function (Builder $target): void {
                    $target->whereNull('task_id')->whereNotNull('requirement_id');
                });
            })
            ->exists();
    }

    public function restore(User $user, FileObject $fileObject): bool
    {
        return $user->global_role === 'admin'
            && $fileObject->scan_status === 'safe'
            && in_array($fileObject->extension, ['pdesk', 'sqlite', 'sqlite3', 'db'], true);
    }
}
