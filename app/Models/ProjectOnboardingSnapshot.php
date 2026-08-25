<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProjectOnboardingSnapshot extends Model
{
    protected $fillable = ['project_id', 'snapshot', 'snapshot_hash', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Onboarding snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Onboarding snapshots are immutable.'));
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
