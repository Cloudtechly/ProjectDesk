<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxonomyTemplate extends Model
{
    protected $fillable = ['name', 'description', 'tree', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['tree' => 'array', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
