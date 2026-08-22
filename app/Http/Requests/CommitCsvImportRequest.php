<?php

namespace App\Http\Requests;

use App\Models\DataJob;
use Illuminate\Foundation\Http\FormRequest;

class CommitCsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $job = $this->route('dataJob');

        return $job instanceof DataJob && $this->user()?->can('commit', $job) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['checksum_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i']];
    }
}
