<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreProjectFileRequest extends ProjectResourceRequest
{
    public function authorize(): bool
    {
        $project = $this->routeProject();

        return $project instanceof Project && Gate::allows('uploadFile', $project);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $project = $this->routeProject();
        $projectId = $project instanceof Project ? $project->id : 0;
        $targetType = (string) $this->input('target_type', 'project');
        $targetIdRules = [
            'nullable',
            'integer',
            'min:1',
            Rule::prohibitedIf($targetType === 'project'),
            Rule::requiredIf(in_array($targetType, ['task', 'requirement'], true)),
        ];
        if ($targetType === 'task') {
            $targetIdRules[] = Rule::exists('tasks', 'id')->where(
                fn (Builder $query): Builder => $query
                    ->where('project_id', $projectId)
                    ->whereNull('archived_at'),
            );
        } elseif ($targetType === 'requirement') {
            $targetIdRules[] = Rule::exists('requirements', 'id')->where(
                fn (Builder $query): Builder => $query
                    ->where('project_id', $projectId)
                    ->whereNull('archived_at'),
            );
        }

        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) config('project-desk.uploads.max_file_kilobytes', 25 * 1024),
            ],
            'target_type' => ['required', 'string', Rule::in(['project', 'task', 'requirement'])],
            'target_id' => $targetIdRules,
        ];
    }

    public function targetType(): string
    {
        $value = $this->validated('target_type');

        return is_string($value) ? $value : 'project';
    }

    public function targetId(): ?int
    {
        $value = $this->validated('target_id');

        return is_numeric($value) ? (int) $value : null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('target_type')) {
            $this->merge(['target_type' => 'project']);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'اختر ملفاً لرفعه.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.max' => 'يتجاوز الملف الحجم الأقصى المسموح.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $project = $this->routeProject();
        $actor = $this->user();
        if ($project instanceof Project && $actor instanceof User) {
            app(ActivityLogger::class)->record(
                $project,
                'project_file.upload_rejected_validation',
                $actor,
                after: ['reason' => 'request_validation', 'error_fields' => array_keys($validator->errors()->toArray())],
                request: $this,
            );
        }

        parent::failedValidation($validator);
    }
}
