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
            'per_page.min' => 'The per page must be at least 1.',
            'per_page.max' => 'The per page must be at most 100.',
            'page.min' => 'The page must be at least 1.',
        ];
    }
}
