<?php

namespace App\Http\Requests;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveIssueRequest extends ProjectResourceRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeBusinessDates(['due_at']);
    }

    public function authorize(): bool
    {
        $project = $this->routeProject();
        $issue = $this->route('issue');
        if (! $project instanceof Project) {
            return false;
        }

        return $issue instanceof Issue
            ? $issue->project_id === $project->id && Gate::allows('update', $issue)
            : Gate::allows('create', [Issue::class, $project]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $issue = $this->route('issue');

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['required', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'resolution' => ['nullable', 'string', 'max:50000'],
            'lock_version' => [$issue instanceof Issue ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->routeProject();
            if ($project instanceof Project && $this->filled('owner_id')
                && ! $this->isActiveProjectMember($project, $this->integer('owner_id'))) {
                $validator->errors()->add('owner_id', 'مالك المشكلة يجب أن يكون عضواً نشطاً في فريق المشروع.');
            }

            if (in_array($this->string('status')->toString(), ['resolved', 'closed'], true)
                && ! $this->filled('resolution')) {
                $validator->errors()->add('resolution', 'يجب تسجيل الحل قبل اعتبار المشكلة محلولة أو مغلقة.');
            }
        }];
    }
}
