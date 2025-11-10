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
            'borrower_doc_type_id' => ['nullable', 'integer', Rule::exists('borrower_doc_types', 'id')],
            'file_name' => ['required_without:borrower_doc_type_id','nullable','string','max:255',],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file','mimes:jpg,jpeg,png,pdf,doc,docx','max:2048',],
        ];
    }

    public function messages(): array
    {
        return [
            'borrower_doc_type_id.exists' => __('common.document.invalid_borrower_doc_type'),
            'file_name.required_without' => __('common.document.name_required_when_no_type'),
            'files.required' => __('common.document.files_required'),
            'files.array' => __('common.document.files_array'),
            'files.min' => __('common.document.files_min'),
            'files.*.file' => __('common.document.each_file_must_be_file'),
            'files.*.mimes' => __('common.document.each_file_invalid_type'),
            'files.*.max' => __('common.document.each_file_too_large'),
        ];
    }

}