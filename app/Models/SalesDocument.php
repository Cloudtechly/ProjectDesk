<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $number
 * @property string $title
 * @property string $status
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property string $currency
 * @property string $discount_rate
 * @property string $tax_rate
 * @property int|null $client_id
 * @property int|null $project_id
 * @property int|null $source_document_id
 * @property string|null $reference
 * @property string|null $notes
 * @property array<string, mixed>|null $client_snapshot
 * @property array<string, mixed>|null $company_snapshot
 * @property int $lock_version
 * @property int $created_by
 * @property-read Client|null $client
 * @property-read Project|null $project
 * @property-read SalesDocument|null $sourceDocument
 * @property-read ProposalDetail|null $proposalDetail
 * @property-read ReceiptDetail|null $receiptDetail
 * @property-read LetterDetail|null $letterDetail
 * @property-read Collection<int, SalesLineItem> $lineItems
 */
class SalesDocument extends Model
{
    public const TEMPLATE_TYPE = 'invoice';

    /** @var list<string> */
    public const TYPES = [self::TEMPLATE_TYPE];

    /** @var list<string> */
    public const TEMPLATE_STATUSES = ['draft', 'archived'];

    /**
     * Historical values remain valid database records, but are deliberately
     * excluded from the invoice-template surface.
     *
     * @var list<string>
     */
    public const LEGACY_TYPES = ['proposal', 'receipt', 'letterhead'];

    protected $fillable = [
        'type', 'number', 'title', 'status', 'client_id', 'project_id', 'source_document_id',
        'issue_date', 'due_date', 'reference', 'currency', 'discount_rate', 'tax_rate', 'notes',
        'client_snapshot', 'company_snapshot', 'lock_version', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date', 'discount_rate' => 'decimal:2',
            'tax_rate' => 'decimal:2', 'client_snapshot' => 'array', 'company_snapshot' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_document_id');
    }

    /** @return HasMany<SalesLineItem, $this> */
    public function lineItems(): HasMany
    {
        return $this->hasMany(SalesLineItem::class)->orderBy('position');
    }

    /** @return HasOne<ProposalDetail, $this> */
    public function proposalDetail(): HasOne
    {
        return $this->hasOne(ProposalDetail::class);
    }

    /** @return HasOne<ReceiptDetail, $this> */
    public function receiptDetail(): HasOne
    {
        return $this->hasOne(ReceiptDetail::class);
    }

    /** @return HasOne<LetterDetail, $this> */
    public function letterDetail(): HasOne
    {
        return $this->hasOne(LetterDetail::class);
    }

    /** @return HasMany<SalesDocument, $this> */
    public function convertedDocuments(): HasMany
    {
        return $this->hasMany(self::class, 'source_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<SalesDocument>  $query
     * @return Builder<SalesDocument>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->invoiceTemplates();

        if ($user->status !== 'active' || $user->archived_at !== null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->global_role === 'admin') {
            return $query;
        }

        if ($user->global_role === 'project_manager') {
            return $query->where('created_by', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<SalesDocument>  $query
     * @return Builder<SalesDocument>
     */
    public function scopeInvoiceTemplates(Builder $query): Builder
    {
        return $query
            ->where('type', self::TEMPLATE_TYPE)
            ->whereIn('status', self::TEMPLATE_STATUSES);
    }

    public function isInvoiceTemplate(): bool
    {
        return $this->type === self::TEMPLATE_TYPE
            && in_array($this->status, self::TEMPLATE_STATUSES, true);
    }

    /**
     * Legacy proposals, receipts, letters and accounting-state invoices must
     * resolve as 404 on every implicit sales-document route.
     *
     * @param  mixed  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return mixed
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where('type', self::TEMPLATE_TYPE)
            ->whereIn('status', self::TEMPLATE_STATUSES);
    }
}
