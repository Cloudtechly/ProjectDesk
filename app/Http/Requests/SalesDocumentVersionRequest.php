<?php

namespace App\Http\Requests;

use App\Models\SalesDocument;
use Illuminate\Foundation\Http\FormRequest;

class SalesDocumentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('salesDocument') instanceof SalesDocument;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:1']];
    }
}
