<?php

namespace App\Http\Requests\Api\V1\Borrower\Payment;

use App\Models\Payment\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'status'       => ['nullable'],
            // Method and Type (BUG-169): sent by the Payments filters, and were
            // dropped here, so the list ignored them.
            'payment_from'     => ['nullable', 'string', 'max:100'],
            'transaction_type' => ['nullable', 'string', Rule::in([Payment::TRANSACTION_TYPE_DEBIT, Payment::TRANSACTION_TYPE_CREDIT])],
            'payee_id'     => ['nullable', 'integer'],
            'partner_id'   => ['nullable', 'integer'],
            'invoice_id'   => ['nullable'],
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
