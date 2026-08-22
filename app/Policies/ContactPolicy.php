<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function before(User $user): ?bool
    {
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return false;
        }

        return null;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->can('view', $contact->client);
    }

    public function create(User $user, Client $client): bool
    {
        return $client->archived_at === null && $user->can('update', $client);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $contact->is_active && $contact->client->archived_at === null && $user->can('update', $contact->client);
    }

    public function archive(User $user, Contact $contact): bool
    {
        return $this->update($user, $contact);
    }

    public function restore(User $user, Contact $contact): bool
    {
        return ! $contact->is_active
            && $contact->client->archived_at === null
            && $user->can('update', $contact->client);
    }
}
