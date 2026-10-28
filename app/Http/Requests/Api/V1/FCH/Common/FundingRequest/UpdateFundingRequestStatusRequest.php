<?php

namespace App\Http\Requests\Api\V1\FCH\Common\FundingRequest;

use App\Http\Requests\Api\V1\BaseApiRequest;

use App\Models\FundingRequest\FundingRequest;
use Illuminate\Validation\Rule;

class UpdateFundingRequestStatusRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'exists:funding_requests,id'],
            'status' => [
                'required', 
                'string', 
                Rule::in([
                    FundingRequest::STATUS_PENDING,
                    FundingRequest::STATUS_IN_PROGRESS,
                    FundingRequest::STATUS_APPROVED,
                    FundingRequest::STATUS_ACCEPTED,
                    FundingRequest::STATUS_REJECTED,
                    FundingRequest::STATUS_CANCELED,
                ])
            ],
            'reject_reason' => ['nullable', 'string', 'required_if:status,' . FundingRequest::STATUS_REJECTED],
            // The status the page was showing - a decision made from an older one is refused (R2 BUG-058).
            'expected_status' => ['nullable', 'string', 'max:255'],
        ];
    }
    
    public function messages(): array
    {
        return [
            'id.required' => __('funding_request.id_required'),
            'id.integer' => __('funding_request.id_integer'),
            'id.exists' => __('funding_request.id_exists'),
            'status.required' => __('funding_request.status_required'),
            'status.in' => __('funding_request.status_in'),
            'reject_reason.required_if' => __('funding_request.reject_reason_required'),
        ];
    }
}
