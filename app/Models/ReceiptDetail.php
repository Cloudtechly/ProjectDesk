<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $sales_document_id
 * @property string $receipt_type
 * @property string $payer
 * @property string $amount
 * @property string|null $amount_words
 * @property string|null $payment_method
 * @property string $purpose
 */
class ReceiptDetail extends Model
{
    protected $fillable = [
        'sales_document_id', 'receipt_type', 'payer', 'amount', 'amount_words',
        'payment_method', 'purpose',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }
}
