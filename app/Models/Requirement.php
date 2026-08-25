<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $code
 * @property string $title
 * @property int|null $owner_id
 * @property int $lock_version
 * @property Carbon|null $archived_at
 * @property-read Project $project
 */
class Requirement extends Model
{
    protected $fillable = [
        'project_id', 'group_id', 'code', 'title', 'description', 'acceptance_criteria', 'type', 'priority',
        'status_id', 'owner_id', 'lock_version', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<WorkflowStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WorkflowStatus::class, 'status_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<Task, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }

    /** @return BelongsTo<RequirementGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(RequirementGroup::class);
    }

    /** @return BelongsToMany<TimelineEntry, $this> */
    public function timelineEntries(): BelongsToMany
    {
        return $this->belongsToMany(TimelineEntry::class, 'requirement_timeline_entry')->withTimestamps();
    }

    /** @return HasMany<RequirementRelation, $this> */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(RequirementRelation::class, 'source_requirement_id');
    }

    /** @return HasMany<RequirementRelation, $this> */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(RequirementRelation::class, 'target_requirement_id');
    }

    /** @return HasMany<RequirementSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(RequirementSource::class);
    }
}
