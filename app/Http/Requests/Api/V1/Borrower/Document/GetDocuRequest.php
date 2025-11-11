<?php

namespace App\Http\Requests\Api\V1\Borrower\Document;

use Illuminate\Foundation\Http\FormRequest;

class GetDocuRequest extends FormRequest
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
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'borrower_doc_type_id' => 'nullable|integer|exists:borrower_doc_types,id',
            'file_name' => 'nullable|string',
            'query' => 'nullable|string',
        ];
    }
}
