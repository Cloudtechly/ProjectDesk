<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $timeline_entry_id
 * @property int|null $organizer_id
 * @property int $lock_version
 * @property Carbon|null $archived_at
 * @property-read TimelineEntry $timelineEntry
 */
class Meeting extends Model
{
    protected $fillable = [
        'timeline_entry_id', 'organizer_id', 'location', 'meeting_url', 'agenda', 'archived_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<TimelineEntry, $this> */
    public function timelineEntry(): BelongsTo
    {
        return $this->belongsTo(TimelineEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_attendees')
            ->withPivot('attendance_status')
            ->withTimestamps();
    }

    /** @return HasOne<MeetingMinutes, $this> */
    public function minutes(): HasOne
    {
        return $this->hasOne(MeetingMinutes::class);
    }
}
