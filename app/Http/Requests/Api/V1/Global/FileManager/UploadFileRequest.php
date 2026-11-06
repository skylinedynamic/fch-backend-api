<?php

namespace App\Http\Requests\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\SafeUpload;

class UploadFileRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'file' => ['required', 'file', SafeUpload::document()],
            'path' => ['required', 'string'],
            'entity_type' => ['required', 'string'],
        ];

        if ($this->input('entity_type') === 'credit-accounts') {
            $rules['entity_id'] = ['nullable'];
        } else {
            $rules['entity_id'] = ['required', 'integer'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'file.required' => __('filemanager.file_required'),
            'path.required' => __('filemanager.path_required'),
            'entity_type.required' => __('filemanager.entity_type_required'),
            'entity_id.required' => __('filemanager.entity_id_required'),
        ];
    }
}
