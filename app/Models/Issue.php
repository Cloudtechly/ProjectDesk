<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $owner_id
 * @property string $title
 * @property string $severity
 * @property string $status
 * @property int $lock_version
 * @property Carbon|null $due_at
 * @property Carbon|null $archived_at
 * @property-read Project $project
 */
class Issue extends Model
{
    protected $fillable = [
        'project_id', 'title', 'description', 'severity', 'status', 'owner_id', 'due_at', 'resolution',
        'archived_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'archived_at' => 'datetime', 'lock_version' => 'integer'];
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
}
