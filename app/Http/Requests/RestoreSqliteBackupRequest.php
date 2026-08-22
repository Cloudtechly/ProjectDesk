<?php

namespace App\Http\Requests;

use App\Models\FileObject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestoreSqliteBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $backup = $this->route('backup');

        return $backup instanceof FileObject && $this->user()?->can('restore', $backup) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirmation' => [
                'required',
                'string',
                Rule::in([(string) config('project-desk.data_center.restore_confirmation', 'RESTORE PROJECT DESK')]),
            ],
            'checksum_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
            'restore_nonce' => ['required', 'string', 'size:129', 'regex:/^[a-f0-9]{64}\.[a-f0-9]{64}$/'],
        ];
    }
}
