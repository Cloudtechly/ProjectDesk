<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $kind
 * @property string $title
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string $status
 * @property int|null $owner_id
 * @property int $lock_version
 * @property Carbon|null $archived_at
 * @property-read Project $project
 */
class TimelineEntry extends Model
{
    protected $fillable = [
        'project_id', 'parent_phase_id', 'kind', 'title', 'starts_at', 'ends_at', 'status',
        'weight_percent', 'completion_criteria', 'is_gate', 'completed_at', 'completed_by',
        'owner_id', 'note', 'metadata', 'archived_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
            'weight_percent' => 'decimal:2',
            'is_gate' => 'boolean',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasOne<Meeting, $this> */
    public function meeting(): HasOne
    {
        return $this->hasOne(Meeting::class);
    }

    /** @return BelongsTo<TimelineEntry, $this> */
    public function parentPhase(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_phase_id');
    }

    /** @return HasMany<TimelineEntry, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(self::class, 'parent_phase_id')->where('kind', 'milestone');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'phase_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsToMany<Requirement, $this> */
    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(Requirement::class, 'requirement_timeline_entry')->withTimestamps();
    }
}
