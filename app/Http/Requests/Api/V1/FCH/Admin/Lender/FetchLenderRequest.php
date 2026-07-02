<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Lender;

use App\Http\Requests\Api\V1\BaseApiRequest;

class FetchLenderRequest extends BaseApiRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page'       => 'nullable|integer|min:1',
            'per_page'   => 'nullable', // Can be integer or 'All' or '-1'
            'sort_field' => 'nullable|string',
            'sort_order' => 'nullable|string|in:asc,desc',
            'search'     => 'nullable|string',
            'filters'    => 'nullable', // Can be array or JSON string
        ];
    }
}
