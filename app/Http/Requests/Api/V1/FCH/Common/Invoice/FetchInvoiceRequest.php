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
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable'],
            'payment_amount' => ['nullable', 'numeric'],
            'borrower_id' => ['nullable'],
            'entity_type' => ['nullable'],
            'entity_id' => ['nullable'],
            'partner_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort_field' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'filters' => ['nullable', 'array'],
            'subscription_id' => ['nullable', 'integer'],
        ];
    }
}
