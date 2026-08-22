<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        $document = $this->route('salesDocument');

        return $document instanceof SalesDocument
            ? Gate::forUser($user)->allows('update', $document)
            : Gate::forUser($user)->allows('create', SalesDocument::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => $this->input('type', SalesDocument::TEMPLATE_TYPE),
            'status' => $this->input('status', 'draft'),
            'client_id' => $this->blankToNull($this->input('client_id')),
            'project_id' => $this->blankToNull($this->input('project_id')),
            'issue_date' => $this->blankToNull($this->input('issue_date')),
            'due_date' => $this->blankToNull($this->input('due_date')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $document = $this->route('salesDocument');

        return [
            'type' => ['required', 'string', Rule::in([SalesDocument::TEMPLATE_TYPE])],
            'number' => ['prohibited'],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(['draft'])],
            'client_id' => [
                'nullable', 'integer',
                Rule::exists('clients', 'id')->where('status', 'active')->whereNull('archived_at'),
            ],
            'project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')->whereNull('archived_at'),
            ],
            'source_document_id' => ['prohibited'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', Rule::in(['LYD', 'USD', 'EUR'])],
            'discount_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'tax_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'lock_version' => [$document instanceof SalesDocument ? 'required' : 'prohibited', 'integer', 'min:1'],

            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.name' => ['required', 'string', 'max:255'],
            'line_items.*.description' => ['nullable', 'string'],
            'line_items.*.quantity' => ['required', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'line_items.*.unit' => ['required', 'string', 'max:40'],
            'line_items.*.unit_price' => ['required', 'decimal:0,2', 'min:0', 'max:999999999999.99'],

            'proposal' => ['prohibited'],
            'receipt' => ['prohibited'],
            'letter' => ['prohibited'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $user = $this->user();
            if (! $user instanceof User) {
                return;
            }

            $projectId = $this->integerOrNull('project_id');
            $clientId = $this->integerOrNull('client_id');

            if ($user->global_role !== 'admin' && $projectId !== null) {
                $projectVisible = Project::query()->visibleTo($user)->whereKey($projectId)->exists();
                if (! $projectVisible) {
                    $validator->errors()->add('project_id', 'المشروع المختار غير متاح لك.');
                }
            }

            if ($user->global_role !== 'admin' && $clientId !== null) {
                $clientVisible = Client::query()->visibleTo($user)->whereKey($clientId)->exists();
                if (! $clientVisible) {
                    $validator->errors()->add('client_id', 'العميل المختار غير متاح لك.');
                }
            }

            if ($projectId !== null && $clientId !== null) {
                $projectMatchesClient = Project::query()
                    ->whereKey($projectId)
                    ->where('client_id', $clientId)
                    ->whereNull('archived_at')
                    ->exists();

                if (! $projectMatchesClient) {
                    $validator->errors()->add('project_id', 'المشروع المختار لا يتبع العميل المحدد.');
                }
            }

            $issueDate = $this->stringOrNull('issue_date');
            $dueDate = $this->stringOrNull('due_date');
            if (! $validator->errors()->hasAny(['issue_date', 'due_date'])
                && $issueDate !== null
                && $dueDate !== null
                && Date::parse($dueDate)->lt(Date::parse($issueDate))) {
                $validator->errors()->add('due_date', 'يجب ألا يسبق تاريخ المعاينة النهائي تاريخ بدايتها.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'line_items.required' => 'أضف بنداً واحداً على الأقل إلى قالب الفاتورة.',
            'line_items.*.quantity.gt' => 'يجب أن تكون كمية البند أكبر من صفر.',
            'line_items.*.unit_price.min' => 'لا يمكن أن يكون سعر الوحدة سالباً.',
            'discount_rate.max' => 'يجب ألا تتجاوز نسبة الخصم 100%.',
            'tax_rate.max' => 'يجب ألا تتجاوز نسبة الضريبة 100%.',
        ];
    }

    private function blankToNull(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private function integerOrNull(string $key): ?int
    {
        $value = filter_var($this->input($key), FILTER_VALIDATE_INT);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
