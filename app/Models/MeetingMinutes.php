<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $meeting_id
 * @property int $recorded_by
 * @property int|null $file_object_id
 * @property int $lock_version
 * @property Carbon $recorded_at
 * @property-read Meeting $meeting
 */
class MeetingMinutes extends Model
{
    protected $fillable = [
        'meeting_id', 'summary', 'decisions', 'action_items', 'file_object_id', 'recorded_by', 'recorded_at',
        'lock_version',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<FileObject, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'file_object_id');
    }
}
