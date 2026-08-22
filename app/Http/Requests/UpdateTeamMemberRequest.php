<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof User && $this->user()?->can('update', $member) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $member = $this->route('member');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member instanceof User ? $member->id : null)],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'global_role' => ['required', Rule::in(['admin', 'project_manager', 'member', 'viewer'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->isSensitiveSelfUpdate($member)),
                'string',
                'current_password:web',
            ],
        ];
    }

    private function isSensitiveSelfUpdate(mixed $member): bool
    {
        $actor = $this->user();
        if (! $member instanceof User || ! $actor instanceof User || ! $actor->is($member)) {
            return false;
        }

        return $this->filled('password')
            || ($this->has('email') && $this->string('email')->toString() !== $member->email)
            || ($this->has('global_role') && $this->string('global_role')->toString() !== $member->global_role);
    }
}
