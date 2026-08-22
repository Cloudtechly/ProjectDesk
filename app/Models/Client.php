<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'created_by', 'code', 'name', 'email', 'phone', 'address', 'status', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<SalesDocument, $this> */
    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->global_role === 'admin') {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('created_by', $user->id)
                ->orWhereHas('projects', fn (Builder $projects) => $projects->whereIn(
                    'projects.id',
                    Project::query()->visibleTo($user)->select('projects.id'),
                ));
        });
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->global_role === 'admin') {
            return $query;
        }

        if ($user->global_role !== 'project_manager') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $manageable) use ($user): void {
            $manageable->where('created_by', $user->id)
                ->orWhereHas('projects', function (Builder $projects) use ($user): void {
                    $projects->where(function (Builder $managed) use ($user): void {
                        $managed->where('manager_id', $user->id)
                            ->orWhereHas('members', fn (Builder $members) => $members
                                ->whereKey($user->id)
                                ->where('project_members.project_role', 'manager')
                                ->where('project_members.status', 'active'));
                    });
                });
        });
    }
}
