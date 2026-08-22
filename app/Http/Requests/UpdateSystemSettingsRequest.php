<?php

namespace App\Http\Requests;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        abort_unless(SystemSettingsService::supportsGroup($this->group()), 404);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('update', new SystemSetting);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->group()) {
            'general' => [
                'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'timezone' => ['sometimes', 'string', 'timezone:all'],
            ],
            'company' => [
                'display_name' => ['sometimes', 'nullable', 'string', 'max:160'],
                'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
                'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'address' => ['sometimes', 'nullable', 'string', 'max:500'],
                'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
                'tax_number' => ['sometimes', 'nullable', 'string', 'max:80'],
                'registration_number' => ['sometimes', 'nullable', 'string', 'max:80'],
                'logo_asset' => ['sometimes', 'string', Rule::in(['/brand/cloudtech-logo.svg'])],
                'invoice_prefix' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/'],
                'number_padding' => ['sometimes', 'integer', 'between:2,8'],
            ],
            'notifications' => [
                'enabled' => ['sometimes', 'boolean'],
                'overdue_tasks' => ['sometimes', 'boolean'],
                'upcoming_tasks' => ['sometimes', 'boolean'],
                'meetings' => ['sometimes', 'boolean'],
                'lead_hours' => ['sometimes', 'integer', 'between:1,168'],
            ],
            'automatic_backup' => [
                'enabled' => ['sometimes', 'boolean'],
                'frequency' => ['sometimes', 'string', Rule::in(['daily', 'weekly'])],
                'time' => ['sometimes', 'date_format:H:i'],
                'retention_count' => ['sometimes', 'integer', 'between:1,90'],
            ],
            'calendar' => [
                'week_start' => ['sometimes', 'integer', 'between:0,6'],
                'weekend_days' => ['sometimes', 'array', 'list', 'min:1'],
                'weekend_days.*' => ['required', 'integer', 'distinct', 'between:0,6'],
            ],
            default => [],
        };
    }

    private function group(): string
    {
        $group = $this->route('group');

        return is_string($group) ? $group : '';
    }
}
