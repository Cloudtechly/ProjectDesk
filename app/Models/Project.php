<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $priority
 * @property int|null $client_id
 * @property int|null $manager_id
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property int $lock_version
 * @property Carbon|null $archived_at
 * @property-read Client|null $client
 * @property-read Contact|null $primaryContact
 * @property-read User|null $manager
 * @property-read WorkflowStatus $status
 */
class Project extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'client_id', 'primary_contact_id', 'manager_id',
        'status_id', 'priority', 'start_date', 'end_date', 'lock_version', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'archived_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return BelongsTo<WorkflowStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WorkflowStatus::class, 'status_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['project_role', 'status'])
            ->withTimestamps();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Requirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class);
    }

    /** @return HasMany<TimelineEntry, $this> */
    public function timelineEntries(): HasMany
    {
        return $this->hasMany(TimelineEntry::class);
    }

    /** @return HasManyThrough<Meeting, TimelineEntry, $this> */
    public function meetings(): HasManyThrough
    {
        return $this->hasManyThrough(
            Meeting::class,
            TimelineEntry::class,
            'project_id',
            'timeline_entry_id',
        );
    }

    /** @return HasMany<Risk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /** @return HasOne<RequirementBook, $this> */
    public function requirementBook(): HasOne
    {
        return $this->hasOne(RequirementBook::class);
    }

    /** @return HasManyThrough<RequirementBookVersion, RequirementBook, $this> */
    public function requirementBookVersions(): HasManyThrough
    {
        return $this->hasManyThrough(
            RequirementBookVersion::class,
            RequirementBook::class,
            'project_id',
            'requirement_book_id',
        );
    }

    /** @return HasMany<SalesDocument, $this> */
    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class);
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->global_role === 'admin') {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user) {
            $visible->where('manager_id', $user->id)
                ->orWhereHas('members', fn (Builder $members) => $members->whereKey($user->id)->where('project_members.status', 'active'));
        });
    }
}
