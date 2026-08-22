<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property int $probability
 * @property int $impact
 * @property string $status
 * @property int $project_id
 * @property int|null $owner_id
 * @property int $lock_version
 * @property Carbon|null $archived_at
 * @property-read Project $project
 */
class Risk extends Model
{
    protected $fillable = [
        'project_id', 'title', 'description', 'probability', 'impact', 'status', 'owner_id', 'mitigation', 'due_at',
        'archived_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'impact' => 'integer',
            'due_at' => 'datetime',
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
}
