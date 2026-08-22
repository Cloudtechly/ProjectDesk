<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $file_object_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property int|null $requirement_id
 * @property int|null $requirement_book_version_id
 * @property int|null $meeting_minutes_id
 * @property Carbon|null $archived_at
 * @property-read FileObject $fileObject
 * @property-read Project $project
 * @property-read Task|null $task
 * @property-read Requirement|null $requirement
 */
class AttachmentLink extends Model
{
    protected $fillable = [
        'file_object_id', 'project_id', 'task_id', 'requirement_id',
        'requirement_book_version_id', 'meeting_minutes_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** @return BelongsTo<FileObject, $this> */
    public function fileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Requirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    /** @return BelongsTo<RequirementBookVersion, $this> */
    public function requirementBookVersion(): BelongsTo
    {
        return $this->belongsTo(RequirementBookVersion::class);
    }

    /** @return BelongsTo<MeetingMinutes, $this> */
    public function meetingMinutes(): BelongsTo
    {
        return $this->belongsTo(MeetingMinutes::class);
    }
}
