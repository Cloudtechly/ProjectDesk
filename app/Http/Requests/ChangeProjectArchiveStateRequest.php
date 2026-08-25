<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class ChangeProjectArchiveStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        if (! $project instanceof Project) {
            return false;
        }

        $ability = $this->routeIs('projects.restore') ? 'restore' : 'archive';

        return $this->user()?->can($ability, $project) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
