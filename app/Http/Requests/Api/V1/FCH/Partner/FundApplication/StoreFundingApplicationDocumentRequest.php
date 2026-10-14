<?php

namespace App\Http\Requests\Api\V1\FCH\Partner\FundApplication;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Borrower\FundingApplication;
use App\Rules\VisibleToUser;

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
            'funding_application_id' => ['required', new VisibleToUser(FundingApplication::class)],
            'document_type_id' => 'required|exists:borrower_doc_types,id',
            'files' => 'required|array|min:1',
        ];
    }
}

