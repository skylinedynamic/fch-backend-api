<?php

namespace App\Http\Requests\Api\V1\Borrower\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;

class FetchFundingRequestRequest extends BaseApiRequest
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
            'funding_sequence_id' => ['nullable', 'integer'],
        ];
    }
    public function messages(): array
    {
        return [
            'per_page.integer' => __('funding_request.per_page_integer'),
            'per_page.min' => __('funding_request.per_page_min'),
            'page.integer' => __('funding_request.page_integer'),
            'page.min' => __('funding_request.page_min'),
            'search.string' => __('funding_request.search_string'),
            'filters.array' => __('funding_request.filters_array'),
            'sort_field.string' => __('funding_request.sort_field_string'),
            'sort_order.in' => __('funding_request.sort_order_in'),
            'status.string' => __('funding_request.status_string'),
        ];
    }
}
