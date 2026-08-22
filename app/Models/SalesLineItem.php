<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_document_id
 * @property string $name
 * @property string|null $description
 * @property string $quantity
 * @property string $unit
 * @property string $unit_price
 * @property int $position
 */
class SalesLineItem extends Model
{
    protected $fillable = [
        'sales_document_id', 'name', 'description', 'quantity', 'unit', 'unit_price', 'position',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'position' => 'integer'];
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }
}
