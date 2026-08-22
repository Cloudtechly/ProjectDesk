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
 * @property string $priority
 * @property int $status_id
 * @property int|null $assignee_id
 * @property Carbon|null $assigned_at
 * @property Carbon $start_at
 * @property Carbon $due_at
 * @property Carbon|null $completed_at
 * @property int $lock_version
 * @property Carbon|null $archived_at
 * @property-read Project $project
 * @property-read WorkflowStatus $status
 * @property-read User|null $assignee
 */
class Task extends Model
{
    protected $fillable = [
        'project_id', 'code', 'title', 'description', 'status_id', 'priority', 'assignee_id',
        'assigned_at', 'start_at', 'due_at', 'completed_at', 'estimated_minutes', 'notes',
        'lock_version', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'start_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'estimated_minutes' => 'integer',
            'lock_version' => 'integer',
        ];
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsToMany<Requirement, $this> */
    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(Requirement::class);
    }

    /** @return HasMany<TaskAssignmentEvent, $this> */
    public function assignmentEvents(): HasMany
    {
        return $this->hasMany(TaskAssignmentEvent::class);
    }
}
