<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocaleRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var array<string, mixed> $supported */
        $supported = config('project-desk.localization.supported', []);

        return [
            'locale' => ['required', 'string', Rule::in(array_keys($supported))],
        ];
    }
}
