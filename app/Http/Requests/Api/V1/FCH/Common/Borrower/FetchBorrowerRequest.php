<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use Illuminate\Foundation\Http\FormRequest;

class FetchBorrowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'        => ['nullable', 'integer'],
            'current_tier'      => ['nullable'],
            'assign_id'         => ['nullable', 'integer'],
            'assigned_user_id'  => ['nullable'],
            'search'            => ['nullable', 'string'],
            'filters'           => ['nullable', 'array'],
            'page'              => ['nullable', 'integer', 'min:1'],
            'per_page'          => ['nullable', 'integer', 'min:1'],
            'sort_field'        => ['nullable', 'string'],
            'sort_order'        => ['nullable', 'in:asc,desc'],
            'status'            => ['nullable'],
            'is_free_trail'     => ['nullable'],
        ];
    }
}
