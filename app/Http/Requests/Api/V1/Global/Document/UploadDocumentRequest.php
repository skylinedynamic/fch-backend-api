<?php

namespace App\Http\Requests\Api\V1\Global\Document;

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
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'borrower_doc_type_id' => ['nullable', 'integer', Rule::exists('borrower_doc_types', 'id')],
            'name' => ['required_without:borrower_doc_type_id', 'string', 'max:255'],
            'file' => ['required_without:files', 'file'],
            'files' => ['required_without:file', 'array'],
            'files.*' => ['file'],
            'disk' => ['nullable', 'string', Rule::in(['local', 'public', 's3', 'sharepoint'])],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => __('common.document.user_id_required'),
            'user_id.exists' => __('common.document.user_not_found'),
            'borrower_doc_type_id.exists' => __('common.document.invalid_borrower_doc_type'),
            'file.required' => __('common.document.file_required'),
            'file.required_without' => __('common.document.file_required'),
            'files.required_without' => __('common.document.files_required'),
            'files.array' => __('common.document.files_array'),
            'files.*.file' => __('common.document.each_file_must_be_file'),
            'name.required_without' => __('common.document.name_required_when_no_type'),
            'disk.in' => __('common.document.disk_invalid'),
        ];
    }

}