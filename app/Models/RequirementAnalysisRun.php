<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequirementAnalysisRun extends Model
{
    protected $fillable = [
        'project_id', 'requirement_book_version_id', 'requested_by', 'status', 'file_fingerprint',
        'instruction_version', 'model', 'context_size', 'page_count', 'injection_risk',
        'cancel_requested', 'attempt_count', 'error_code', 'error_message', 'metadata',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'context_size' => 'integer', 'page_count' => 'integer', 'cancel_requested' => 'boolean',
            'attempt_count' => 'integer', 'metadata' => 'array', 'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<RequirementBookVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RequirementBookVersion::class, 'requirement_book_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<RequirementCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(RequirementCandidate::class, 'analysis_run_id');
    }
}
