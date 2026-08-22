<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportError extends Model
{
    protected $fillable = ['data_job_id', 'sheet', 'row_number', 'field', 'code', 'message'];

    protected function casts(): array
    {
        return ['row_number' => 'integer'];
    }

    /** @return BelongsTo<DataJob, $this> */
    public function dataJob(): BelongsTo
    {
        return $this->belongsTo(DataJob::class);
    }
}
