<?php

namespace App\Http\Requests\Api\V1\Borrower\Funding;

use Illuminate\Foundation\Http\FormRequest;

class FetchFundingReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
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
