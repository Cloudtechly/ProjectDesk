<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            ...$this->profileRules($user->id),
            'current_password' => [
                Rule::requiredIf(fn (): bool => $user instanceof User
                    && $this->has('email')
                    && $this->string('email')->toString() !== $user->email),
                'string',
                'current_password:web',
            ],
        ];
    }
}
