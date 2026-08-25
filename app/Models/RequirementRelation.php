<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequirementRelation extends Model
{
    protected $fillable = ['project_id', 'source_requirement_id', 'target_requirement_id', 'type', 'created_by'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Requirement, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'source_requirement_id');
    }

    /** @return BelongsTo<Requirement, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'target_requirement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
