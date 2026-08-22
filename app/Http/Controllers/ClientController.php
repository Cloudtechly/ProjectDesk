<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListClientsRequest;
use App\Http\Requests\SaveClientRequest;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(ListClientsRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorize('viewAny', Client::class);

        $filters = $request->validated();
        $clientsQuery = Client::query()->visibleTo($user);

        match ($filters['archived'] ?? 'active') {
            'only' => $clientsQuery->whereNotNull('archived_at'),
            'all' => null,
            default => $clientsQuery->whereNull('archived_at'),
        };

        $clients = $clientsQuery
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function ($query, string $search): void {
                $like = '%'.$search.'%';
                $query->where(function ($matches) use ($like): void {
                    $matches->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhereHas('contacts', function ($contacts) use ($like): void {
                            $contacts->where(function ($contactFields) use ($like): void {
                                $contactFields->where('name', 'like', $like)
                                    ->orWhere('email', 'like', $like)
                                    ->orWhere('phone', 'like', $like);
                            });
                        });
                });
            })
            ->with([
                'contacts' => fn ($contacts) => $contacts
                    ->orderByDesc('is_primary')
                    ->orderByDesc('is_active')
                    ->orderBy('name'),
                'projects' => fn ($projects) => $projects
                    ->visibleTo($user)
                    ->with('status:id,label,color,semantic')
                    ->orderBy('name'),
            ])
            ->withCount([
                'projects as projects_count' => fn ($projects) => $projects->visibleTo($user),
            ])
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString()
            ->through(function (Client $client) use ($user): Client {
                $client->setAttribute('can_restore', $user->can('restore', $client));

                return $client;
            });

        return Inertia::render('clients/index', [
            'clients' => $clients,
            'filters' => $filters,
            'canCreate' => $user->can('create', Client::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('clients/create');
    }

    public function store(SaveClientRequest $request, ActivityLogger $activityLogger): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $client = DB::transaction(function () use ($request, $user, $activityLogger): Client {
            $client = Client::query()->create([
                ...$request->validated(),
                'created_by' => $user->id,
            ]);
            $activityLogger->record($client, 'client.created', $user, after: $client->toArray(), request: $request);

            return $client;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت إضافة العميل بنجاح.']);

        return to_route('clients.show', $client);
    }

    public function show(Client $client, Request $request): Response
    {
        $this->authorize('view', $client);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $client->load([
            'contacts' => fn ($contacts) => $contacts
                ->orderByDesc('is_primary')
                ->orderByDesc('is_active')
                ->orderBy('name'),
            'projects' => fn ($projects) => $projects
                ->visibleTo($user)
                ->with('status:id,label,color,semantic')
                ->orderBy('name'),
        ])->loadCount([
            'projects as projects_count' => fn ($projects) => $projects->visibleTo($user),
        ]);

        return Inertia::render('clients/show', [
            'client' => [
                ...$client->only([
                    'id', 'code', 'name', 'email', 'phone', 'address', 'status', 'archived_at',
                    'projects_count',
                ]),
                'contacts' => $client->contacts->map(fn ($contact): array => [
                    ...$contact->only(['id', 'name', 'role', 'email', 'phone', 'is_primary', 'is_active']),
                    'canUpdate' => $user->can('update', $contact),
                    'canArchive' => $user->can('archive', $contact),
                    'canRestore' => $user->can('restore', $contact),
                ])->values(),
                'projects' => $client->projects,
                'can' => [
                    'update' => $user->can('update', $client),
                    'archive' => $user->can('archive', $client),
                    'restore' => $user->can('restore', $client),
                    'createContact' => $user->can('create', [Contact::class, $client]),
                    'createProject' => $user->can('create', Project::class) && $user->can('update', $client),
                ],
            ],
        ]);
    }

    public function edit(Client $client): Response
    {
        $this->authorize('update', $client);

        return Inertia::render('clients/edit', ['client' => $client->load('contacts')]);
    }

    public function update(
        SaveClientRequest $request,
        Client $client,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $client, $user, $activityLogger): void {
            $before = $client->toArray();
            $client->update($request->validated());
            $activityLogger->record($client, 'client.updated', $user, $before, $client->fresh()->toArray(), $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات العميل.']);

        return to_route('clients.show', $client);
    }

    public function archive(
        Request $request,
        Client $client,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        $this->authorize('archive', $client);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $hasActiveProjects = $client->projects()
            ->whereNull('archived_at')
            ->whereHas('status', fn ($status) => $status->whereNotIn('semantic', ['done', 'cancelled']))
            ->exists();

        if ($hasActiveProjects) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'لا يمكن أرشفة العميل قبل إغلاق مشاريعه النشطة أو نقلها إلى عميل آخر.',
            ]);

            return back()->withErrors([
                'archive' => 'للعميل مشاريع نشطة يجب إغلاقها أو نقلها أولاً.',
            ]);
        }

        DB::transaction(function () use ($request, $client, $user, $activityLogger): void {
            $before = $client->toArray();
            $client->update(['status' => 'archived', 'archived_at' => now()]);
            $client->contacts()->update(['is_primary' => false, 'is_active' => false]);
            $activityLogger->record($client, 'client.archived', $user, $before, $client->fresh()->toArray(), $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة العميل دون حذف سجله أو مشاريعه.']);

        return to_route('clients.index');
    }

    public function restore(
        Request $request,
        Client $client,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        $this->authorize('restore', $client);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $client, $user, $activityLogger): void {
            $before = $client->toArray();
            $client->update(['status' => 'active', 'archived_at' => null]);
            $activityLogger->record($client, 'client.restored', $user, $before, $client->fresh()->toArray(), $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة العميل، ويمكن إعادة تفعيل جهات اتصاله عند الحاجة.']);

        return to_route('clients.show', $client);
    }
}
