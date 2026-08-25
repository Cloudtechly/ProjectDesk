<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequirementGroup extends Model
{
    protected $fillable = ['project_id', 'category_id', 'name', 'description', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<RequirementCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RequirementCategory::class, 'category_id');
    }

    /** @return HasMany<Requirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class, 'group_id');
    }
}
