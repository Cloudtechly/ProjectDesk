<?php

namespace App\Http\Requests\Settings;

use App\Services\SystemSettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferencesRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $system = app(SystemSettingsService::class)->group('notifications');
        $maxLeadHours = max(1, min(168, (int) ($system['lead_hours'] ?? 24)));

        return [
            'enabled' => ['required', 'boolean'],
            'overdue_tasks' => ['required', 'boolean'],
            'upcoming_tasks' => ['required', 'boolean'],
            'meetings' => ['required', 'boolean'],
            'lead_hours' => ['required', 'integer', 'min:1', "max:{$maxLeadHours}"],
        ];
    }
}
