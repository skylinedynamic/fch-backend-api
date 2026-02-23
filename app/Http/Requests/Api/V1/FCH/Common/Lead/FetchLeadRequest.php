<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;

class FetchLeadRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'filters' => ['nullable', 'array'],
            'sort_field' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc,ASC,DESC'],
            'lead_type' => ['nullable'],
            'status' => ['nullable'],
            'salesperson_id' => ['nullable'],
            'partner_id' => ['nullable', 'integer'],
        ];
    }
}
