<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class SaveContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');
        $contact = $this->route('contact');

        if (! $client instanceof Client) {
            return false;
        }

        if ($contact instanceof Contact) {
            return $contact->client_id === $client->id && Gate::allows('update', $contact);
        }

        return Gate::allows('create', [Contact::class, $client]);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->route('contact')) {
            $this->merge([
                'is_primary' => $this->boolean('is_primary'),
                'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $contact = $this->route('contact');
            $isPrimary = $this->has('is_primary')
                ? $this->boolean('is_primary')
                : ($contact instanceof Contact && $contact->is_primary);
            $isActive = $this->has('is_active')
                ? $this->boolean('is_active')
                : (! $contact instanceof Contact || $contact->is_active);

            if ($isPrimary && ! $isActive) {
                $validator->errors()->add('is_primary', 'لا يمكن تعيين جهة اتصال غير نشطة كجهة أساسية.');
            }
        }];
    }
}
