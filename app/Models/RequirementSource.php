<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequirementSource extends Model
{
    protected $fillable = [
        'requirement_id', 'requirement_book_version_id', 'analysis_run_id', 'locator_type',
        'locator', 'excerpt', 'confidence',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'float'];
    }

    /** @return BelongsTo<Requirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    /** @return BelongsTo<RequirementBookVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RequirementBookVersion::class, 'requirement_book_version_id');
    }

    /** @return BelongsTo<RequirementAnalysisRun, $this> */
    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(RequirementAnalysisRun::class);
    }
}
