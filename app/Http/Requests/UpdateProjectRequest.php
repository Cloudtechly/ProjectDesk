<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAssignmentGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('projects', 'code')->ignore($project instanceof Project ? $project->id : null)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('archived_at')->where('status', 'active')],
            'primary_contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('is_active', true)],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('archived_at')->where('status', 'active')],
            'status_id' => [
                'required',
                'integer',
                Rule::exists('workflow_statuses', 'id')->where('entity_type', 'project')->where('is_active', true),
            ],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('archived_at')->where('status', 'active')],
            'members' => ['array'],
            'members.*' => ['array:id,role'],
            'members.*.id' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->whereNull('archived_at')->where('status', 'active')],
            'members.*.role' => ['nullable', Rule::in(['manager', 'member', 'viewer'])],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $user = $this->user();
            if ($this->filled('client_id') && $user instanceof User) {
                $canManageClient = Client::query()
                    ->manageableBy($user)
                    ->whereKey($this->integer('client_id'))
                    ->exists();

                if (! $canManageClient) {
                    $validator->errors()->add('client_id', 'لا تملك صلاحية ربط المشروع بهذا العميل.');
                }
            }

            if ($this->filled('primary_contact_id')) {
                $belongsToClient = Contact::query()
                    ->whereKey($this->integer('primary_contact_id'))
                    ->where('client_id', $this->integer('client_id'))
                    ->where('is_active', true)
                    ->exists();

                if (! $belongsToClient) {
                    $validator->errors()->add('primary_contact_id', 'جهة الاتصال المختارة لا تتبع العميل المحدد.');
                }
            }

            app(ProjectAssignmentGuard::class)->addProjectErrors(
                $validator,
                $this->input('manager_id'),
                (array) $this->input('members', []),
                (array) $this->input('member_ids', []),
            );
        }];
    }
}
