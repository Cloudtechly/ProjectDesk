<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkflowStatusesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        abort_unless(in_array($this->entityType(), WorkflowStatus::ENTITY_TYPES, true), 404);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('update', new WorkflowStatus);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'statuses' => ['required', 'array', 'list', 'min:1'],
            'statuses.*' => ['required', 'array:id,label,semantic,color,position,is_active'],
            'statuses.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('workflow_statuses', 'id')->where('entity_type', $this->entityType()),
            ],
            'statuses.*.label' => ['required', 'string', 'max:100'],
            'statuses.*.semantic' => ['required', Rule::in(WorkflowStatus::SEMANTICS)],
            'statuses.*.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'statuses.*.position' => ['required', 'integer', 'between:0,65535', 'distinct'],
            'statuses.*.is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'statuses.required' => 'يجب إرسال قائمة الحالات كاملة.',
            'statuses.list' => 'يجب أن تكون الحالات قائمة مرتبة.',
            'statuses.*.array' => 'يسمح فقط بحقول الحالة القابلة للتعديل.',
            'statuses.*.id.distinct' => 'لا يمكن تكرار الحالة في المجموعة.',
            'statuses.*.id.exists' => 'إحدى الحالات لا تنتمي إلى نوع سير العمل المطلوب.',
            'statuses.*.label.required' => 'اسم الحالة مطلوب.',
            'statuses.*.label.max' => 'اسم الحالة يجب ألا يتجاوز 100 حرف.',
            'statuses.*.semantic.in' => 'التصنيف الدلالي للحالة غير صالح.',
            'statuses.*.color.regex' => 'اللون يجب أن يكون بصيغة HEX مثل #406386.',
            'statuses.*.position.distinct' => 'ترتيب كل حالة يجب أن يكون فريداً.',
        ];
    }

    public function entityType(): string
    {
        $entityType = $this->route('entityType');

        return is_string($entityType) ? $entityType : '';
    }
}
