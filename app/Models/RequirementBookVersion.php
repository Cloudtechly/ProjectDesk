<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $requirement_book_id
 * @property string|null $title
 * @property int $version_number
 * @property string $status
 * @property int $file_object_id
 * @property string|null $note
 * @property int $uploaded_by
 * @property Carbon $uploaded_at
 * @property bool $is_current
 * @property int $lock_version
 * @property Carbon|null $archived_at
 * @property-read RequirementBook $requirementBook
 * @property-read FileObject $fileObject
 * @property-read User $uploader
 */
class RequirementBookVersion extends Model
{
    protected $fillable = [
        'requirement_book_id', 'title', 'version_number', 'status', 'file_object_id', 'note',
        'uploaded_by', 'uploaded_at', 'is_current', 'lock_version', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'uploaded_at' => 'datetime',
            'is_current' => 'boolean',
            'lock_version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RequirementBook, $this> */
    public function requirementBook(): BelongsTo
    {
        return $this->belongsTo(RequirementBook::class);
    }

    /** @return BelongsTo<FileObject, $this> */
    public function fileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<AttachmentLink, $this> */
    public function attachmentLinks(): HasMany
    {
        return $this->hasMany(AttachmentLink::class);
    }

    /** @return HasMany<RequirementAnalysisRun, $this> */
    public function analysisRuns(): HasMany
    {
        return $this->hasMany(RequirementAnalysisRun::class);
    }
}
