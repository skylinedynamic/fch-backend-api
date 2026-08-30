<?php

namespace App\Http\Requests\Api\V1\Borrower\Document;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['nullable', 'integer', Rule::exists('borrower_doc_types', 'id')],
            'files' => ['required', 'array', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_type_id.exists' => __('borrower_validation.invalid_borrower_doc_type'),
            'files.required' => __('borrower_validation.files_required'),
            'files.array' => __('borrower_validation.files_array'),
            'files.min' => __('borrower_validation.files_min'),
        ];
    }

}