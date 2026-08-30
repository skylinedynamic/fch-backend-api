<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;

class FetchFundingSequencingRequest extends BaseApiRequest
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
            'per_page'   => 'nullable',
            'sort_field' => 'nullable|string',
            'sort_order' => 'nullable|string',
            'search'     => 'nullable|string',
            'filters'    => 'nullable',
            
            // extra parameters from original code
            'status'           => 'nullable|string',
            'funding_group_id' => 'nullable|integer',
            'funding_type_id'  => 'nullable|integer',
            'first'            => 'nullable|integer',
            'rows'             => 'nullable|integer',
        ];
    }
}
