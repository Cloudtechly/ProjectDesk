<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string|null $resource_type
 * @property string|null $format
 * @property string $status
 * @property int|null $file_object_id
 * @property int $created_by
 * @property array<string, mixed>|null $summary
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property-read FileObject|null $fileObject
 */
class DataJob extends Model
{
    protected $fillable = [
        'type', 'resource_type', 'format', 'status', 'file_object_id', 'created_by',
        'summary', 'error_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FileObject, $this> */
    public function fileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ImportError, $this> */
    public function importErrors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }
}
