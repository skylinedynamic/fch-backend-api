<?php

namespace App\Http\Requests\Api\V1\Borrower\CapitalAccess;

use App\Http\Requests\Api\V1\BaseApiRequest;

use App\Models\CreditBuilder\CapitalAccessApplication;

class SubmitCapitalAccessStepRequest extends BaseApiRequest
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
        $statuses = implode(',', CapitalAccessApplication::STATUSES);
        $approvedStatus = CapitalAccessApplication::STATUS_APPROVED;

        // No bound taken from the step's own estimate. It used to be clamped to
        // est_limit_min..est_limit_max, so an approval outside the estimated band
        // could not be recorded at all - but the band is a projection and the
        // approval is a fact, and the two do not have to agree. The estimate is
        // shown beside the field as guidance instead. min:0 is not a cap; it only
        // says a credit limit is not a negative number.
        return [
            'status'       => "required|string|in:{$statuses}",
            'credit_limit' => "required_if:status,{$approvedStatus}|nullable|numeric|min:0",
            'notes'        => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credit_limit.required' => 'Approved Credit Limit is required.',
            'credit_limit.numeric'  => 'Approved Credit Limit must be a number.',
            'credit_limit.min'      => 'Approved Credit Limit cannot be negative.',
        ];
    }
}
