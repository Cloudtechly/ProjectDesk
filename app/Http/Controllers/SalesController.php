<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalesDocumentVersionRequest;
use App\Http\Requests\SaveSalesDocumentRequest;
use App\Models\Client;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SalesLineItem;
use App\Models\User;
use App\Services\SalesCalculator;
use App\Services\SalesDocumentService;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesController extends Controller
{
    public function index(
        Request $request,
        SalesCalculator $calculator,
        SystemSettingsService $settings,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorize('viewAny', SalesDocument::class);

        $requestedStatus = $request->string('status')->toString() === 'archived'
            ? 'archived'
            : 'draft';
        $projects = Project::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'client_id']);
        $clients = Client::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->where('status', 'active')
            ->with(['contacts' => fn ($query) => $query
                ->where('is_primary', true)
                ->where('is_active', true)
                ->select(['id', 'client_id', 'name', 'role', 'email', 'phone'])])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'address'])
            ->map(function (Client $client): array {
                $primaryContact = $client->contacts->first();

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'phone' => $client->phone,
                    'address' => $client->address,
                    'primary_contact' => $primaryContact?->only([
                        'name', 'role', 'email', 'phone',
                    ]),
                ];
            })
            ->values();
        $company = $settings->group('company');
        $canCreate = $user->can('create', SalesDocument::class);

        $documents = SalesDocument::query()
            ->visibleTo($user)
            ->where('status', $requestedStatus)
            ->with(['client:id,name', 'project:id,name', 'lineItems'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->toString().'%';
                $query->where(function ($document) use ($search): void {
                    $document->where('number', 'like', $search)
                        ->orWhere('title', 'like', $search)
                        ->orWhere('reference', 'like', $search)
                        ->orWhereHas('client', fn ($client) => $client->where('name', 'like', $search))
                        ->orWhereHas('project', fn ($project) => $project->where('name', 'like', $search));
                });
            })
            ->when($request->filled('project'), fn ($query) => $query->where('project_id', $request->integer('project')))
            ->orderByRaw('issue_date IS NULL')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SalesDocument $document): array => $this->summaryPayload($document, $calculator));

        return Inertia::render('sales/index', [
            'documents' => $documents,
            'filters' => [
                ...$request->only(['q', 'project']),
                'status' => $requestedStatus,
            ],
            'projects' => $projects,
            'clients' => $clients,
            'formProjects' => $projects,
            'formClients' => $clients,
            'company' => [
                'name' => $company['display_name'] ?: 'CloudTech',
                'display_name' => $company['display_name'] ?: 'CloudTech',
                'legal_name' => $company['legal_name'] ?: ($company['display_name'] ?: 'CloudTech'),
                'email' => $company['email'],
                'phone' => $company['phone'],
                'address' => $company['address'],
                'website' => $company['website'],
                'logo_asset' => $company['logo_asset'],
            ],
            'documentTypes' => [SalesDocument::TEMPLATE_TYPE],
            'statuses' => [
                SalesDocument::TEMPLATE_TYPE => SalesDocument::TEMPLATE_STATUSES,
            ],
            'canCreate' => $canCreate,
            'canCreateProjectless' => $canCreate,
        ]);
    }

    public function show(SalesDocument $salesDocument, SalesCalculator $calculator): JsonResponse
    {
        $this->authorize('view', $salesDocument);
        $salesDocument->load(['client', 'project', 'lineItems', 'creator:id,name']);

        return response()->json([
            'document' => $this->documentPayload($salesDocument, $calculator),
        ]);
    }

    public function store(
        SaveSalesDocumentRequest $request,
        SalesDocumentService $service,
        SalesCalculator $calculator,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $document = $service->create($request->validated(), $user, $request);

        return $this->mutationResponse(
            $request,
            $document,
            $calculator,
            'تم إنشاء قالب الفاتورة بنجاح.',
            201,
        );
    }

    public function update(
        SaveSalesDocumentRequest $request,
        SalesDocument $salesDocument,
        SalesDocumentService $service,
        SalesCalculator $calculator,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $document = $service->update($salesDocument, $request->validated(), $user, $request);

        return $this->mutationResponse(
            $request,
            $document,
            $calculator,
            'تم تحديث قالب الفاتورة بنجاح.',
        );
    }

    public function archive(
        SalesDocumentVersionRequest $request,
        SalesDocument $salesDocument,
        SalesDocumentService $service,
        SalesCalculator $calculator,
    ): JsonResponse|RedirectResponse {
        $this->authorize('archive', $salesDocument);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $document = $service->archive($salesDocument, $request->integer('lock_version'), $user, $request);

        return $this->mutationResponse(
            $request,
            $document,
            $calculator,
            'تمت أرشفة قالب الفاتورة مع الاحتفاظ بسجله.',
        );
    }

    public function restore(
        SalesDocumentVersionRequest $request,
        SalesDocument $salesDocument,
        SalesDocumentService $service,
        SalesCalculator $calculator,
    ): JsonResponse|RedirectResponse {
        $this->authorize('restore', $salesDocument);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $document = $service->restore($salesDocument, $request->integer('lock_version'), $user, $request);

        return $this->mutationResponse(
            $request,
            $document,
            $calculator,
            'تمت استعادة قالب الفاتورة وأصبح مسودة قابلة للتعديل.',
        );
    }

    public function duplicate(
        SalesDocumentVersionRequest $request,
        SalesDocument $salesDocument,
        SalesDocumentService $service,
        SalesCalculator $calculator,
    ): JsonResponse|RedirectResponse {
        $this->authorize('duplicate', $salesDocument);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $copy = $service->duplicate(
            $salesDocument,
            $request->integer('lock_version'),
            $user,
            $request,
        );

        return $this->mutationResponse(
            $request,
            $copy,
            $calculator,
            'تم إنشاء نسخة مستقلة من قالب الفاتورة برقم جديد.',
            201,
        );
    }

    private function mutationResponse(
        Request $request,
        SalesDocument $document,
        SalesCalculator $calculator,
        string $message,
        int $status = 200,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'document' => $this->documentPayload($document, $calculator),
            ], $status);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('sales.index');
    }

    /** @return array<string, mixed> */
    private function documentPayload(SalesDocument $document, SalesCalculator $calculator): array
    {
        $document->loadMissing(['client', 'project', 'lineItems', 'creator:id,name']);

        return [
            ...$this->summaryPayload($document, $calculator),
            'reference' => $document->reference,
            'notes' => $document->notes,
            'clientId' => $document->client_id,
            'projectId' => $document->project_id,
            'storedStatus' => $document->status,
            'discountRate' => $document->discount_rate,
            'taxRate' => $document->tax_rate,
            'lockVersion' => $document->lock_version,
            'clientSnapshot' => $document->client_snapshot,
            'companySnapshot' => $document->company_snapshot,
            'lineItems' => $document->lineItems->map(fn (SalesLineItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unitPrice' => $item->unit_price,
                'position' => $item->position,
            ])->values(),
            'createdBy' => $document->creator?->only(['id', 'name']),
            'permissions' => [
                'update' => request()->user()?->can('update', $document) ?? false,
                'archive' => request()->user()?->can('archive', $document) ?? false,
                'restore' => request()->user()?->can('restore', $document) ?? false,
                'duplicate' => request()->user()?->can('duplicate', $document) ?? false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function summaryPayload(SalesDocument $document, SalesCalculator $calculator): array
    {
        $items = $document->lineItems->map(fn (SalesLineItem $item): array => [
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->all();
        $snapshotName = is_array($document->client_snapshot)
            ? ($document->client_snapshot['name'] ?? null)
            : null;
        $client = $document->getRelation('client');

        return [
            'id' => $document->id,
            'number' => $document->number,
            'type' => SalesDocument::TEMPLATE_TYPE,
            'title' => $document->title,
            'client' => $client instanceof Client ? $client->name : (is_string($snapshotName) ? $snapshotName : null),
            'project' => $document->project?->name,
            'issueDate' => $document->issue_date?->toDateString(),
            'dueDate' => $document->due_date?->toDateString(),
            'currency' => $document->currency,
            'status' => $document->status,
            'totals' => $calculator->calculate($items, $document->discount_rate, $document->tax_rate),
        ];
    }
}
