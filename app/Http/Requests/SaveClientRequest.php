<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client
            ? $this->user()?->can('update', $client) === true
            : $this->user()?->can('create', Client::class) === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->route('client') && ! $this->has('status')) {
            $this->merge(['status' => 'active']);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $client = $this->route('client');

        return [
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('clients', 'code')->ignore($client instanceof Client ? $client->id : null),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
