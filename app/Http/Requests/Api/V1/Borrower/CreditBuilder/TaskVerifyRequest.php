<?php

namespace App\Http\Requests\Api\V1\Borrower\CreditBuilder;

use Illuminate\Foundation\Http\FormRequest;

class TaskVerifyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_ids' => 'required|array|min:1',
            'task_ids.*' => 'required|integer|exists:tier_tasks,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'task_ids.required' => __('borrower_validation.task_ids_required'),
            'task_ids.array' => __('borrower_validation.task_ids_must_be_array'),
            'task_ids.min' => __('borrower_validation.task_ids_min'),
            'task_ids.*.required' => __('borrower_validation.task_id_required'),
            'task_ids.*.integer' => __('borrower_validation.task_id_must_be_integer'),
            'task_ids.*.exists' => __('borrower_validation.task_id_not_found'),
        ];
    }
}
