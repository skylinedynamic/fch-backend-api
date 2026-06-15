<?php

namespace App\Http\Requests\Api\V1\FCH\Common\FundingReview;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFundingReviewStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'exists:funding_reviews,id'],
            'status' => ['required', 'string', 'in:Pending,Approved,Rejected'],
            'reject_reason' => ['nullable', 'string', 'required_if:status,Rejected'],
        ];
    }
    
    public function messages(): array
    {
        return [
            'reject_reason.required_if' => __('funding_review.reject_reason_required'),
        ];
    }
}
