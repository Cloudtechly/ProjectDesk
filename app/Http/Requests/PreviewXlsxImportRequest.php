<?php

namespace App\Http\Requests;

use App\Models\DataJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewXlsxImportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['resource_type' => $this->route('resource')]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', DataJob::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resource_type' => ['required', Rule::in(['clients', 'tasks'])],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('project-desk.data_center.xlsx_max_kilobytes', 10 * 1024),
                'extensions:xlsx',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip',
            ],
        ];
    }
}
