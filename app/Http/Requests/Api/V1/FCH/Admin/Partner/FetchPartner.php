<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use Illuminate\Foundation\Http\FormRequest;

class FetchPartner extends FormRequest
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
            'partner_id'        => ['nullable', 'integer'],
            'assigned_user_id'  => ['nullable', 'integer'],
            'search'            => ['nullable', 'string'],
            'filters'           => ['nullable', 'array'],
            'page'              => ['nullable', 'integer', 'min:1'],
            'per_page'          => ['nullable', 'integer', 'min:1'],
            'sort_field'        => ['nullable', 'string'],
            'sort_order'        => ['nullable', 'in:asc,desc'],
        ];
    }
}
