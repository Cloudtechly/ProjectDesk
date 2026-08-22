<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\RequirementBookVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreRequirementBookVersionRequest extends ProjectResourceRequest
{
    public function authorize(): bool
    {
        $project = $this->routeProject();

        return $project instanceof Project
            && Gate::allows('create', [RequirementBookVersion::class, $project]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'version_number' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'status' => ['nullable', Rule::in(['draft', 'under_review', 'approved', 'superseded'])],
            'note' => ['nullable', 'string', 'max:20000'],
            'is_current' => ['nullable', 'boolean'],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('project-desk.uploads.max_file_kilobytes', 25 * 1024),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'عنوان كراسة المتطلبات مطلوب.',
            'file.required' => 'ملف كراسة المتطلبات مطلوب.',
            'file.max' => 'يتجاوز الملف الحجم الأقصى المسموح.',
        ];
    }
}
