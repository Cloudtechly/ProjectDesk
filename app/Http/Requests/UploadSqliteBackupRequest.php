<?php

namespace App\Http\Requests;

use App\Models\DataJob;
use Illuminate\Foundation\Http\FormRequest;

class UploadSqliteBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DataJob::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) config('project-desk.data_center.backup_max_kilobytes', 512 * 1024),
                'extensions:pdesk,sqlite,sqlite3,db',
                'mimetypes:application/vnd.projectdesk.backup,application/vnd.sqlite3,application/x-sqlite3,application/x-sqlite,application/octet-stream,application/zip',
            ],
        ];
    }
}
