<?php

namespace App\Http\Requests\Api\V1\FCH\Common\CreditBuilder;

use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVerificationStatusRequest extends FormRequest
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
            'request_id' => 'required|integer|exists:borrower_task_verification_requests,id',
            'status' => ['required', Rule::in(array_values(BorrowerTaskVerificationRequest::STATUS))],
            'rejection_reason' => 'required_if:status,' . BorrowerTaskVerificationRequest::REJECTED . '|nullable|string',
        ];
    }
}
