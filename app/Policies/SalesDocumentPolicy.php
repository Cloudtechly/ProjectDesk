<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\User;

class SalesDocumentPolicy
{
    public function before(User $user): ?bool
    {
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->global_role, ['admin', 'project_manager'], true);
    }

    public function view(User $user, SalesDocument $document): bool
    {
        return $document->isInvoiceTemplate() && $this->canAccess($user, $document);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return in_array($user->global_role, ['admin', 'project_manager'], true);
    }

    public function update(User $user, SalesDocument $document): bool
    {
        return $document->status === 'draft' && $this->canManage($user, $document);
    }

    public function archive(User $user, SalesDocument $document): bool
    {
        return $document->status === 'draft' && $this->canManage($user, $document);
    }

    public function restore(User $user, SalesDocument $document): bool
    {
        return $document->status === 'archived' && $this->canManage($user, $document);
    }

    public function duplicate(User $user, SalesDocument $document): bool
    {
        return $this->canManage($user, $document);
    }

    private function canAccess(User $user, SalesDocument $document): bool
    {
        return $user->global_role === 'admin'
            || ($user->global_role === 'project_manager' && $document->created_by === $user->id);
    }

    private function canManage(User $user, SalesDocument $document): bool
    {
        return $document->isInvoiceTemplate() && $this->canAccess($user, $document);
    }
}
