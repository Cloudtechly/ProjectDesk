<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDocumentService
{
    public function __construct(
        private readonly SalesDocumentNumberGenerator $numberGenerator,
        private readonly SalesDocumentLifecycle $lifecycle,
        private readonly SalesCalculator $calculator,
        private readonly ActivityLogger $activityLogger,
        private readonly SystemSettingsService $settings,
    ) {}

    /** @param array<string, mixed> $validated */
    public function create(array $validated, User $actor, ?Request $request = null): SalesDocument
    {
        return DB::transaction(function () use ($validated, $actor, $request): SalesDocument {
            $attributes = $this->documentAttributes($validated);
            if (! $this->lifecycle->canInitialize($attributes['type'], $attributes['status'])) {
                throw ValidationException::withMessages([
                    'status' => 'يمكن إنشاء قالب الفاتورة كمسودة فقط.',
                ]);
            }

            $client = $this->findClient($attributes['client_id']);
            $year = $attributes['issue_date'] === null
                ? Date::today()->year
                : Date::parse($attributes['issue_date'])->year;
            $attributes['number'] = $this->numberGenerator->reserve(SalesDocument::TEMPLATE_TYPE, $year);
            $attributes['client_snapshot'] = $client === null ? null : $this->clientSnapshot($client);
            $attributes['company_snapshot'] = $this->companySnapshot();
            $attributes['lock_version'] = 1;
            $attributes['created_by'] = $actor->id;

            $document = SalesDocument::query()->create($attributes);
            $this->replaceLineItems($document, $validated);
            $document = $this->loadDocument($document);
            $this->activityLogger->record(
                $document,
                'sales_document.created',
                $actor,
                after: $this->auditSnapshot($document),
                request: $request,
            );

            return $document;
        }, 5);
    }

    /** @param array<string, mixed> $validated */
    public function update(
        SalesDocument $document,
        array $validated,
        User $actor,
        ?Request $request = null,
    ): SalesDocument {
        return DB::transaction(function () use ($document, $validated, $actor, $request): SalesDocument {
            $locked = SalesDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertTemplate($locked);
            $this->assertVersion($locked, (int) $validated['lock_version']);
            if (! $this->lifecycle->canTransition($locked->type, $locked->status, 'draft')) {
                throw ValidationException::withMessages([
                    'status' => 'استعد القالب المؤرشف قبل تعديله.',
                ]);
            }

            $locked = $this->loadDocument($locked);
            $before = $this->auditSnapshot($locked);
            $attributes = $this->documentAttributes($validated);
            $newClientId = $attributes['client_id'];
            if ($newClientId !== $locked->client_id) {
                $client = $this->findClient($newClientId);
                $attributes['client_snapshot'] = $client === null ? null : $this->clientSnapshot($client);
            }
            $attributes['lock_version'] = $locked->lock_version + 1;

            $locked->update($attributes);
            $this->replaceLineItems($locked, $validated);
            $fresh = $locked->fresh();
            abort_unless($fresh instanceof SalesDocument, 404);
            $updated = $this->loadDocument($fresh);
            $this->activityLogger->record(
                $updated,
                'sales_document.updated',
                $actor,
                $before,
                $this->auditSnapshot($updated),
                $request,
            );

            return $updated;
        }, 5);
    }

    public function archive(
        SalesDocument $document,
        int $expectedVersion,
        User $actor,
        ?Request $request = null,
    ): SalesDocument {
        return $this->transition($document, 'archived', $expectedVersion, $actor, $request);
    }

    public function restore(
        SalesDocument $document,
        int $expectedVersion,
        User $actor,
        ?Request $request = null,
    ): SalesDocument {
        return $this->transition($document, 'draft', $expectedVersion, $actor, $request, 'restored');
    }

    public function duplicate(
        SalesDocument $document,
        int $expectedVersion,
        User $actor,
        ?Request $request = null,
    ): SalesDocument {
        return DB::transaction(function () use ($document, $expectedVersion, $actor, $request): SalesDocument {
            $source = SalesDocument::query()
                ->with(['client', 'project', 'lineItems'])
                ->lockForUpdate()
                ->findOrFail($document->id);
            $this->assertTemplate($source);
            $this->assertVersion($source, $expectedVersion);

            if ($source->client !== null
                && ($source->client->archived_at !== null || $source->client->status !== 'active')) {
                throw ValidationException::withMessages([
                    'document' => 'لا يمكن نسخ القالب قبل استعادة العميل المرتبط به.',
                ]);
            }
            if ($source->project !== null && $source->project->archived_at !== null) {
                throw ValidationException::withMessages([
                    'document' => 'لا يمكن نسخ قالب مرتبط بمشروع مؤرشف.',
                ]);
            }

            $rawIssueDate = $source->getRawOriginal('issue_date');
            $numberYear = is_string($rawIssueDate) && $rawIssueDate !== ''
                ? Date::parse($rawIssueDate)->year
                : Date::today()->year;
            $copy = SalesDocument::query()->create([
                'type' => SalesDocument::TEMPLATE_TYPE,
                'number' => $this->numberGenerator->reserve(SalesDocument::TEMPLATE_TYPE, $numberYear),
                'title' => 'نسخة من '.$source->title,
                'status' => 'draft',
                'client_id' => $source->client_id,
                'project_id' => $source->project_id,
                'source_document_id' => null,
                'issue_date' => $source->issue_date?->toDateString(),
                'due_date' => $source->due_date?->toDateString(),
                'reference' => $source->reference,
                'currency' => $source->currency,
                'discount_rate' => $source->discount_rate,
                'tax_rate' => $source->tax_rate,
                'notes' => $source->notes,
                'client_snapshot' => $source->client_snapshot,
                'company_snapshot' => $source->company_snapshot,
                'lock_version' => 1,
                'created_by' => $actor->id,
            ]);

            $copy->lineItems()->createMany($source->lineItems->map(
                fn ($item): array => [
                    'name' => $item->name,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'position' => $item->position,
                ],
            )->all());

            $copy = $this->loadDocument($copy);
            $this->activityLogger->record(
                $copy,
                'sales_document.duplicated',
                $actor,
                after: [
                    ...$this->auditSnapshot($copy),
                    'copied_from_id' => $source->id,
                    'copied_from_number' => $source->number,
                ],
                request: $request,
            );
            $this->activityLogger->record(
                $source,
                'sales_document.copy_created',
                $actor,
                after: ['copy_id' => $copy->id, 'copy_number' => $copy->number],
                request: $request,
            );

            return $copy;
        }, 5);
    }

    private function transition(
        SalesDocument $document,
        string $status,
        int $expectedVersion,
        User $actor,
        ?Request $request,
        ?string $action = null,
    ): SalesDocument {
        return DB::transaction(function () use ($document, $status, $expectedVersion, $actor, $request, $action): SalesDocument {
            $locked = SalesDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertTemplate($locked);
            $this->assertVersion($locked, $expectedVersion);
            if (! $this->lifecycle->canTransition($locked->type, $locked->status, $status)
                || $locked->status === $status) {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن تطبيق هذه الحالة على قالب الفاتورة في حالته الحالية.',
                ]);
            }

            $before = ['status' => $locked->status, 'lock_version' => $locked->lock_version];
            $locked->update([
                'status' => $status,
                'lock_version' => $locked->lock_version + 1,
            ]);
            $fresh = $locked->fresh();
            abort_unless($fresh instanceof SalesDocument, 404);
            $updated = $this->loadDocument($fresh);
            $this->activityLogger->record(
                $updated,
                'sales_document.'.($action ?? $status),
                $actor,
                $before,
                ['status' => $updated->status, 'lock_version' => $updated->lock_version],
                $request,
            );

            return $updated;
        }, 5);
    }

    private function assertTemplate(SalesDocument $document): void
    {
        abort_unless($document->isInvoiceTemplate(), 404);
    }

    private function assertVersion(SalesDocument $document, int $expectedVersion): void
    {
        if ($document->lock_version !== $expectedVersion) {
            abort(409, 'عُدّل القالب في جلسة أخرى. حدّث الصفحة ثم أعد المحاولة.');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *   type: string, title: string, status: string, client_id: int|null,
     *   project_id: int|null, source_document_id: null, issue_date: string|null,
     *   due_date: string|null, reference: mixed, currency: string,
     *   discount_rate: mixed, tax_rate: mixed, notes: mixed
     * }
     */
    private function documentAttributes(array $validated): array
    {
        return [
            'type' => SalesDocument::TEMPLATE_TYPE,
            'title' => (string) $validated['title'],
            'status' => 'draft',
            'client_id' => $this->positiveIntegerOrNull($validated['client_id'] ?? null),
            'project_id' => $this->positiveIntegerOrNull($validated['project_id'] ?? null),
            'source_document_id' => null,
            'issue_date' => $this->nullableString($validated['issue_date'] ?? null),
            'due_date' => $this->nullableString($validated['due_date'] ?? null),
            'reference' => $validated['reference'] ?? null,
            'currency' => (string) $validated['currency'],
            'discount_rate' => $validated['discount_rate'],
            'tax_rate' => $validated['tax_rate'],
            'notes' => $validated['notes'] ?? null,
        ];
    }

    /** @param array<string, mixed> $validated */
    private function replaceLineItems(SalesDocument $document, array $validated): void
    {
        $rawItems = $validated['line_items'] ?? [];
        $items = [];
        if (is_array($rawItems)) {
            foreach (array_values($rawItems) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $items[] = [
                    'name' => (string) $item['name'],
                    'description' => $this->nullableString($item['description'] ?? null),
                    'quantity' => (string) $item['quantity'],
                    'unit' => (string) $item['unit'],
                    'unit_price' => (string) $item['unit_price'],
                    'position' => $index + 1,
                ];
            }
        }

        $this->calculator->calculate($items, $document->discount_rate, $document->tax_rate);
        $document->lineItems()->delete();
        $document->lineItems()->createMany($items);
    }

    private function findClient(?int $clientId): ?Client
    {
        return $clientId === null ? null : Client::query()->findOrFail($clientId);
    }

    /** @return array<string, mixed> */
    private function clientSnapshot(Client $client): array
    {
        $primaryContact = $client->contacts()->where('is_primary', true)->where('is_active', true)->first();

        return [
            'id' => $client->id,
            'code' => $client->getAttribute('code'),
            'name' => $client->getAttribute('name'),
            'email' => $client->getAttribute('email'),
            'phone' => $client->getAttribute('phone'),
            'address' => $client->getAttribute('address'),
            'primary_contact' => $primaryContact === null ? null : [
                'name' => $primaryContact->getAttribute('name'),
                'role' => $primaryContact->getAttribute('role'),
                'email' => $primaryContact->getAttribute('email'),
                'phone' => $primaryContact->getAttribute('phone'),
            ],
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function companySnapshot(): array
    {
        $company = $this->settings->group('company');
        $displayName = is_string($company['display_name'] ?? null) && $company['display_name'] !== ''
            ? $company['display_name']
            : 'CloudTech';
        $legalName = is_string($company['legal_name'] ?? null) && $company['legal_name'] !== ''
            ? $company['legal_name']
            : $displayName;

        return [
            'name' => $displayName,
            'display_name' => $displayName,
            'legal_name' => $legalName,
            'email' => $company['email'] ?? null,
            'phone' => $company['phone'] ?? null,
            'address' => $company['address'] ?? null,
            'website' => $company['website'] ?? null,
            'tax_number' => $company['tax_number'] ?? null,
            'registration_number' => $company['registration_number'] ?? null,
            'logo_asset' => $company['logo_asset'] ?? '/brand/cloudtech-logo.svg',
            'app_name' => (string) config('app.name', 'Project Desk'),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function loadDocument(SalesDocument $document): SalesDocument
    {
        return $document->load(['client', 'project', 'lineItems']);
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(SalesDocument $document): array
    {
        $items = $document->lineItems->map(fn ($item): array => [
            'name' => $item->name,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => $item->unit_price,
            'position' => $item->position,
        ])->all();

        return [
            'document' => Arr::except($document->attributesToArray(), ['updated_at']),
            'line_items' => $items,
            'totals' => $this->calculator->calculate($items, $document->discount_rate, $document->tax_rate),
        ];
    }
}
