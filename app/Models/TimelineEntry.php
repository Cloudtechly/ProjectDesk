<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'project_id', 'kind', 'title', 'starts_at', 'ends_at', 'status', 'owner_id', 'note', 'metadata',
        'archived_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
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
}
