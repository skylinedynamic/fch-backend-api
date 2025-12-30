<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class UploadBorrowerDocumentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['required', 'integer', Rule::exists('borrowers', 'id')],
            'entity_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', 'string'],
            'document_name' => ['required', 'array', 'min:1'],
            'file_path' => ['required', 'array', 'min:1'],
            'document_type_id' => ['nullable', 'integer', Rule::exists('borrower_doc_types', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'borrower_id.required' => __('borrower_validation.borrower_id_required'),
            'borrower_id.exists' => __('borrower_validation.borrower_id_exists'),
            'document_type_id.exists' => __('borrower_validation.invalid_borrower_doc_type'),
            'files.required' => __('borrower_validation.files_required'),
            'files.array' => __('borrower_validation.files_array'),
            'files.min' => __('borrower_validation.files_min'),
        ];
    }
}
