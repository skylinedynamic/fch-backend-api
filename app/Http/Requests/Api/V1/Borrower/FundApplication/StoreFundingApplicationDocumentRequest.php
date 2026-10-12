<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreFundingApplicationDocumentRequest extends FormRequest
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
            'funding_application_id' => ['required', Rule::exists('funding_applications', 'id')->where('borrower_id', Auth::user()?->entity_id)],
            'document_type_id' => 'required|exists:borrower_doc_types,id',
            'files' => 'required|array|min:1',
        ];
    }
}
