<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Payment;

use Illuminate\Foundation\Http\FormRequest;

class FetchPayment extends FormRequest
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
            'search'       => ['nullable', 'string'],
            'status'       => ['nullable', 'string'],
            'payee_id'     => ['nullable', 'integer'],
            'partner_id'   => ['nullable', 'integer'],
            'date_from'    => ['nullable', 'date'],
            'date_to'      => ['nullable', 'date'],
            'date_range'   => ['nullable', 'array'],
            'date_range.*' => ['nullable', 'date'],
            'page'         => ['nullable', 'integer', 'min:1'],
            'per_page'     => ['nullable', 'integer', 'min:1'],
            'sort_field'   => ['nullable', 'string'],
            'sort_order'   => ['nullable', 'in:asc,desc'],
            'filters'      => ['nullable', 'array'],
        ];
    }
}
