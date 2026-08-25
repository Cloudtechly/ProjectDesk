<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequirementCategory extends Model
{
    protected $fillable = ['project_id', 'name', 'description', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<RequirementGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(RequirementGroup::class, 'category_id')->orderBy('position');
    }
}
