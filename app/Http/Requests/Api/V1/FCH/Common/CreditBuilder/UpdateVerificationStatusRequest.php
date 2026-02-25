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
            'request_id' => 'required|array',
            'request_id.*' => 'required|integer|exists:borrower_task_verification_requests,id',
            'status' => ['required', Rule::in(array_values(BorrowerTaskVerificationRequest::STATUS))],
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
            'request_id.required' => __('verification_center.request_id_required'),
            'request_id.array' => __('verification_center.request_id_array'),
            'request_id.*.required' => __('verification_center.request_id_invalid'),
            'request_id.*.integer' => __('verification_center.request_id_invalid'),
            'request_id.*.exists' => __('verification_center.request_id_invalid'),
            'status.required' => __('verification_center.status_required'),
            'status.in' => __('verification_center.status_invalid'),
            'note.required_if' => __('verification_center.note_required'),
        ];
    }
}
