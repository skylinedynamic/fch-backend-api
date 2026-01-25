<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class FetchInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
            'status'    => ['nullable'],
            'cycle'     => ['nullable', 'string'],
            'payee'     => ['nullable', 'string'],
            'search'    => ['nullable', 'string'],
            'per_page'  => ['nullable', 'integer', 'min:1'],
            'page'      => ['nullable', 'integer', 'min:1'],
        ];
    }
}
