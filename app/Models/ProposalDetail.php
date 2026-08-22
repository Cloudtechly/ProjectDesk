<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $sales_document_id
 * @property string|null $subtitle
 * @property string|null $summary
 * @property string|null $objectives
 * @property string|null $deliverables
 * @property bool $includes_contract
 * @property string|null $contract_terms
 */
class ProposalDetail extends Model
{
    protected $fillable = [
        'sales_document_id', 'subtitle', 'summary', 'objectives', 'deliverables',
        'includes_contract', 'contract_terms',
    ];

    protected function casts(): array
    {
        return ['includes_contract' => 'boolean'];
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }
}
