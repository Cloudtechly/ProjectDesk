<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
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
        return $user->status === 'active';
    }

    public function view(User $user, Client $client): bool
    {
        return Client::query()->visibleTo($user)->whereKey($client->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->status === 'active' && in_array($user->global_role, ['admin', 'project_manager'], true);
    }

    public function update(User $user, Client $client): bool
    {
        return $client->archived_at === null
            && Client::query()->manageableBy($user)->whereKey($client->getKey())->exists();
    }

    public function archive(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    public function restore(User $user, Client $client): bool
    {
        return $client->archived_at !== null
            && Client::query()->manageableBy($user)->whereKey($client->getKey())->exists();
    }
}
