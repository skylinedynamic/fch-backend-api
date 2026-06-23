<?php

namespace App\Http\Requests\Api\V1\FCH\Common\FundingReview;

use Illuminate\Foundation\Http\FormRequest;

class FetchFundingReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page'      => ['nullable', 'integer', 'min:1'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'search'        => ['nullable', 'string'],
            'filters'       => ['nullable', 'array'],
            'sort_field'    => ['nullable', 'string'],
            'sort_order'    => ['nullable', 'in:asc,desc'],
            'status'        => ['nullable', 'string'],
        ];
    }
}
