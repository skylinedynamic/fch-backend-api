<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\TierTask;

use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Middleware handles this
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'tasks' => 'required|array',
            'tasks.*.task_id' => 'required|integer|exists:tier_tasks,id',
            'tasks.*.migrated_task_id' => 'nullable|integer|exists:migrated_tasks,id',
            'borrower_id' => 'required|integer|exists:borrowers,id',
            'status' => ['required', Rule::in([
                BorrowerTaskVerificationRequest::COMPLETED,
                BorrowerTaskVerificationRequest::REJECTED
            ])],
            'note' => 'required_if:status,' . BorrowerTaskVerificationRequest::REJECTED . '|nullable|string',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tasks.required' => __('credit_builder.validation_task_ids_required'),
            'tasks.array' => __('credit_builder.validation_task_ids_array'),
            'tasks.*.task_id.required' => __('credit_builder.validation_task_ids_item_required'),
            'tasks.*.task_id.integer' => __('credit_builder.validation_task_ids_item_integer'),
            'tasks.*.task_id.exists' => __('credit_builder.validation_task_ids_item_exists'),
            'borrower_id.required' => __('credit_builder.validation_borrower_id_required'),
            'borrower_id.exists' => __('credit_builder.validation_borrower_id_exists'),
            'status.required' => __('credit_builder.validation_status_required'),
            'status.in' => __('credit_builder.validation_status_in'),
            'note.required_if' => __('credit_builder.validation_note_required_if'),
        ];
    }
}
