<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\BorrowerDocument;
use Illuminate\Validation\Rule;

class UpdateDocumentStatusRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(BorrowerDocument::STATUSES)],
            'rejection_reason' => [Rule::requiredIf($this->status === BorrowerDocument::STATUS_REJECTED), 'string', 'nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => __('borrower_validation.status_required'),
            'status.in' => __('borrower_validation.status_in'),
            'rejection_reason.required_if' => __('borrower_validation.rejection_reason_required'),
            'rejection_reason.string' => __('borrower_validation.rejection_reason_string'),
        ];
    }
}
