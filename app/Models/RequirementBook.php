<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RequirementBook extends Model
{
    protected $fillable = ['project_id', 'title'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<RequirementBookVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RequirementBookVersion::class);
    }

    /** @return HasOne<RequirementBookVersion, $this> */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(RequirementBookVersion::class)
            ->where('is_current', true)
            ->whereNull('archived_at');
    }
}
