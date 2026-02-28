<?php

namespace App\Http\Requests\Api\V1\FCH\Common\CreditBuilder;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use Illuminate\Validation\Rule;

class UpdateVerificationStatusRequest extends BaseApiRequest
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
            'request_ids' => 'required|array',
            'request_ids.*' => 'required|integer|exists:borrower_task_verification_requests,id',
            'status' => ['required', Rule::in(array_values(BorrowerTaskVerificationRequest::STATUS))],
            'note' => 'required_if:status,' . BorrowerTaskVerificationRequest::REJECTED . '|nullable|string',
            'is_api' => 'nullable|boolean',
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
            'request_ids.required' => __('verification_center.request_ids_required'),
            'request_ids.array' => __('verification_center.request_ids_array'),
            'request_ids.*.required' => __('verification_center.request_ids_invalid'),
            'request_ids.*.integer' => __('verification_center.request_ids_invalid'),
            'request_ids.*.exists' => __('verification_center.request_ids_invalid'),
            'status.required' => __('verification_center.status_required'),
            'status.in' => __('verification_center.status_invalid'),
            'note.required_if' => __('verification_center.note_required'),
        ];
    }
}
