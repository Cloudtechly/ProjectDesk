<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequirementCandidate extends Model
{
    protected $fillable = [
        'analysis_run_id', 'candidate_key', 'category_name', 'group_name', 'type', 'title',
        'description', 'acceptance_criteria', 'priority', 'relations', 'ambiguities',
        'source_locator_type', 'source_locator', 'source_excerpt', 'confidence', 'status',
        'change_type', 'matched_requirement_id', 'affected_entities', 'decided_by', 'decided_at',
        'approved_requirement_id',
    ];

    protected function casts(): array
    {
        return [
            'acceptance_criteria' => 'array', 'relations' => 'array', 'ambiguities' => 'array',
            'confidence' => 'float', 'decided_at' => 'datetime',
            'affected_entities' => 'array',
        ];
    }

    /** @return BelongsTo<RequirementAnalysisRun, $this> */
    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(RequirementAnalysisRun::class, 'analysis_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<Requirement, $this> */
    public function approvedRequirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'approved_requirement_id');
    }

    /** @return BelongsTo<Requirement, $this> */
    public function matchedRequirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'matched_requirement_id');
    }
}
