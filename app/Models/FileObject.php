<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $disk
 * @property string $storage_key
 * @property string $original_name
 * @property string $mime_type
 * @property string|null $extension
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property string $scan_status
 * @property int $uploaded_by
 * @property Carbon $uploaded_at
 */
class FileObject extends Model
{
    protected $hidden = ['disk', 'storage_key'];

    protected $fillable = [
        'disk', 'storage_key', 'original_name', 'mime_type', 'extension', 'size_bytes',
        'checksum_sha256', 'scan_status', 'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'uploaded_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<DataJob, $this> */
    public function dataJobs(): HasMany
    {
        return $this->hasMany(DataJob::class);
    }

    /** @return HasMany<AttachmentLink, $this> */
    public function attachmentLinks(): HasMany
    {
        return $this->hasMany(AttachmentLink::class);
    }

    /** @return HasMany<RequirementBookVersion, $this> */
    public function requirementBookVersions(): HasMany
    {
        return $this->hasMany(RequirementBookVersion::class);
    }

    /** @return HasMany<MeetingMinutes, $this> */
    public function meetingMinutes(): HasMany
    {
        return $this->hasMany(MeetingMinutes::class);
    }
}
