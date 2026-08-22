<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task && $this->user()?->can('updateStatus', $task) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status_id' => [
                'required',
                'integer',
                Rule::exists('workflow_statuses', 'id')->where('entity_type', 'task')->where('is_active', true),
            ],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
