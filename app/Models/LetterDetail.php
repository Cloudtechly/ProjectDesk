<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $sales_document_id
 * @property string $recipient
 * @property string $subject
 * @property string $body
 * @property string|null $closing
 * @property string|null $signatory
 */
class LetterDetail extends Model
{
    protected $fillable = [
        'sales_document_id', 'recipient', 'subject', 'body', 'closing', 'signatory',
    ];

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }
}
