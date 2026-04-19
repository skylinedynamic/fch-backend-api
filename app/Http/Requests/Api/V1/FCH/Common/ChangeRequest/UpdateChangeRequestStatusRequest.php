<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ChangeRequest;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\BorrowerChangeRequest;

class UpdateChangeRequestStatusRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:' . implode(',', [
                    BorrowerChangeRequest::STATUS_PENDING,
                    BorrowerChangeRequest::STATUS_APPROVED,
                    BorrowerChangeRequest::STATUS_REJECTED,
                ])],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
