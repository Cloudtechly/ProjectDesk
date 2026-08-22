<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $entity_type
 * @property string $code
 * @property string $label
 * @property string $semantic
 * @property string $color
 * @property int $position
 * @property bool $is_active
 */
class WorkflowStatus extends Model
{
    /** @var list<string> */
    public const ENTITY_TYPES = ['project', 'task', 'requirement'];

    /**
     * Workflow semantics are deliberately immutable through the settings API.
     * Other services use them to calculate completion and initial-state rules.
     *
     * @var list<string>
     */
    public const SEMANTICS = ['open', 'in_progress', 'done', 'cancelled'];

    public const INITIAL_SEMANTIC = 'open';

    protected $fillable = [
        'entity_type', 'code', 'label', 'semantic', 'color', 'position', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'status_id');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status_id');
    }

    /** @return HasMany<Requirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class, 'status_id');
    }
}
