<?php

namespace App\Http\Requests;

use App\Models\SalesDocument;
use Illuminate\Foundation\Http\FormRequest;

class ConvertProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('salesDocument');

        return $document instanceof SalesDocument
            && $this->user()?->can('convertToInvoice', $document) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'يجب ألا يسبق تاريخ استحقاق الفاتورة تاريخ إصدارها.',
        ];
    }
}
