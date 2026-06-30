<?php

namespace App\Http\Requests\Api\V1\FCH\Common\FundingRequest;

use App\Http\Requests\Api\V1\BaseApiRequest;

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
            'status' => ['required', 'string', 'in:Pending,In Progress,Approved,Accepted,Rejected'],
            'reject_reason' => ['nullable', 'string', 'required_if:status,Rejected'],
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
