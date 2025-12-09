<?php

namespace App\Http\Requests\Api\V1\Borrower\MyApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;

class GetMyApplicationRequest extends BaseApiRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            'per_page.min' => __('borrower_validation.per_page_min'),
            'per_page.max' => __('borrower_validation.per_page_max'),
            'page.min' => __('borrower_validation.page_min'),
        ];
    }
}
