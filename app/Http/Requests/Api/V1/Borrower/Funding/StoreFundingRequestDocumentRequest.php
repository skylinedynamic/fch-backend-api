<?php

namespace App\Http\Requests\Api\V1\Borrower\Funding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFundingRequestDocumentRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'funding_sequence_id' => 'required|exists:funding_sequencings,id',
            'document_type_id' => 'required|exists:borrower_doc_types,id',
            'files' => 'required|array|min:1',
        ];
    }
}
