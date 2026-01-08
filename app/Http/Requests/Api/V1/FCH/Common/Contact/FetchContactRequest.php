<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Contact;

use App\Http\Requests\Api\V1\BaseApiRequest;

class FetchContactRequest extends BaseApiRequest
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
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string'],
            'sort_field' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
            'filters' => ['nullable', 'array'],
        ];
    }
}
