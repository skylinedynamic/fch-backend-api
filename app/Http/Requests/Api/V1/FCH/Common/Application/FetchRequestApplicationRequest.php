<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Application;

use Illuminate\Foundation\Http\FormRequest;

class FetchRequestApplicationRequest extends FormRequest
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
            // Additional filters can be passed in the 'filters' array or as top-level params if specific ones vary often
            'status'        => ['nullable', 'string'],
            'start_date'    => ['nullable', 'date'],
            'end_date'      => ['nullable', 'date'],
            'funding_type'  => ['nullable', 'string'],
            // Scopes the list to one client, for the client record's tab.
            'borrower_id'   => ['nullable', 'integer'],
        ];
    }
}
