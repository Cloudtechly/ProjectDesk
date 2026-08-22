<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveContactRequest;
use App\Models\Client;
use App\Models\Contact;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function store(
        SaveContactRequest $request,
        Client $client,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $client, $user, $activityLogger): void {
            $data = $request->validated();
            $data['is_primary'] = (bool) ($data['is_primary'] ?? false);
            $data['is_active'] = (bool) ($data['is_active'] ?? true);

            if (! $data['is_active']) {
                $data['is_primary'] = false;
            }

            if ($data['is_primary']) {
                $client->contacts()->where('is_primary', true)->update(['is_primary' => false]);
            }

            $contact = $client->contacts()->create($data);
            $activityLogger->record($contact, 'contact.created', $user, after: $contact->toArray(), request: $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت إضافة جهة الاتصال.']);

        return to_route('clients.show', $client);
    }

    public function update(
        SaveContactRequest $request,
        Client $client,
        Contact $contact,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        abort_unless($contact->client_id === $client->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $client, $contact, $user, $activityLogger): void {
            $before = $contact->toArray();
            $data = $request->validated();

            if (($data['is_active'] ?? $contact->is_active) === false) {
                $data['is_primary'] = false;
            }

            if (($data['is_primary'] ?? false) === true) {
                $client->contacts()
                    ->whereKeyNot($contact->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $contact->update($data);
            $activityLogger->record($contact, 'contact.updated', $user, $before, $contact->fresh()->toArray(), $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث جهة الاتصال.']);

        return to_route('clients.show', $client);
    }

    public function archive(
        Request $request,
        Client $client,
        Contact $contact,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        abort_unless($contact->client_id === $client->id, 404);
        $this->authorize('archive', $contact);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $contact, $user, $activityLogger): void {
            $before = $contact->toArray();
            $contact->update(['is_primary' => false, 'is_active' => false]);
            $activityLogger->record($contact, 'contact.archived', $user, $before, $contact->fresh()->toArray(), $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة جهة الاتصال دون حذفها.']);

        return to_route('clients.show', $client);
    }

    public function restore(
        Request $request,
        Client $client,
        Contact $contact,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        abort_unless($contact->client_id === $client->id, 404);
        $this->authorize('restore', $contact);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $contact, $user, $activityLogger): void {
            $before = $contact->toArray();
            $contact->update(['is_active' => true]);
            $activityLogger->record($contact, 'contact.restored', $user, $before, $contact->fresh()->toArray(), $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة جهة الاتصال.']);

        return to_route('clients.show', $client);
    }
}
