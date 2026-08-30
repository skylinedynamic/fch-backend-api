<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ChangeRequest;

use App\Http\Requests\Api\V1\BaseApiRequest;

class FetchChangeRequestsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'partner_id' => ['nullable', 'integer'],
            'borrower_id' => ['nullable', 'integer'],
            'sort_field' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
